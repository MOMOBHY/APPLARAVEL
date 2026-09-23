<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\DeclarationDeces;
use App\Models\DeclarationNaissance;
use App\Models\DemandePermission;
use App\Models\NoteService;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Structure;
use App\Models\TypePermission;
use App\Models\User;
use App\Services\EtatCivilService;
use App\Services\NoteWorkflowService;
use App\Services\PermissionWorkflowService;
use App\Services\PieceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Couche de compatibilité avec le frontend historique (frontend/, servi tel quel
 * depuis public/gfp). Expose l'ancien contrat /api/* adossé aux nouveaux
 * modèles et au workflow Cas 1 (≤ 3 j) / Cas 2 (> 3 j).
 */
class LegacyApiController extends Controller
{
    /** Profil de connexion demandé -> codes rôles acceptés. */
    private const PROFILE_ROLES = [
        'AGENT' => ['ROLE_AGENT'],
        'RESPONSABLE' => ['ROLE_GESTIONNAIRE_RH', 'ROLE_SOUS_DIRECTEUR', 'ROLE_DIRECTEUR'],
        'SOUS_DIRECTEUR' => ['ROLE_SOUS_DIRECTEUR'],
        'DRH' => ['ROLE_DRH'],
        'DIRECTEUR' => ['ROLE_DIRECTEUR'],
        'DIRCAB' => ['ROLE_DIRECTEUR'],
        'SECRETAIRE' => ['ROLE_SECRETAIRE'],
        'SERVICE' => ['ROLE_SERVICE_ADMINISTRATIF'],
        'CHEF_SERVICE' => ['ROLE_CHEF_DE_SERVICE'],
        'DIRCAB' => ['ROLE_DIRECTEUR_CABINET', 'ROLE_DIRECTEUR'],
        'ADMINISTRATEUR' => ['ROLE_ADMIN_DSI'],
    ];

    /** Rôle simple (admin) -> nouveaux codes. */
    private const SIMPLE_ROLES = [
        'AGENT' => ['ROLE_AGENT'],
        'RESPONSABLE' => ['ROLE_GESTIONNAIRE_RH', 'ROLE_SOUS_DIRECTEUR'],
        'DRH' => ['ROLE_DRH'],
        'ADMINISTRATEUR' => ['ROLE_ADMIN_DSI'],
    ];

    private function resolveRoleCodes(string $input): ?array
    {
        $code = strtoupper(trim($input));
        if (isset(self::SIMPLE_ROLES[$code])) {
            return self::SIMPLE_ROLES[$code];
        }
        if (isset(self::LEGACY_ROLE_CODES[$code])) {
            return self::LEGACY_ROLE_CODES[$code];
        }

        return Role::where('code', $code)->exists() ? [$code] : null;
    }

    /** Ancien code rôle (inscription) -> nouveaux codes. */
    private const LEGACY_ROLE_CODES = [
        'ROLE_AGENT' => ['ROLE_AGENT'],
        'ROLE_CHEF_SERVICE' => ['ROLE_GESTIONNAIRE_RH', 'ROLE_SOUS_DIRECTEUR'],
        'ROLE_SOUS_DIRECTEUR' => ['ROLE_SOUS_DIRECTEUR'],
        'ROLE_DIRECTEUR' => ['ROLE_DIRECTEUR'],
        'ROLE_DIRCAB' => ['ROLE_DIRECTEUR'],
        'ROLE_DRH' => ['ROLE_DRH'],
        'ROLE_SECRETAIRE' => ['ROLE_SECRETAIRE'],
        'ROLE_ADMIN_DSI' => ['ROLE_ADMIN_DSI'],
    ];

    /** Nouveau code -> ancien code pour affichage admin. */
    private const NEW_TO_LEGACY_CODE = [
        'ROLE_AGENT' => 'ROLE_AGENT',
        'ROLE_GESTIONNAIRE_RH' => 'ROLE_CHEF_SERVICE',
        'ROLE_SOUS_DIRECTEUR' => 'ROLE_SOUS_DIRECTEUR',
        'ROLE_DIRECTEUR' => 'ROLE_DIRECTEUR',
        'ROLE_DRH' => 'ROLE_DRH',
        'ROLE_SECRETAIRE' => 'ROLE_SECRETAIRE',
        'ROLE_ADMIN_DSI' => 'ROLE_ADMIN_DSI',
    ];

    // ---------------------------------------------------------------
    // Authentification (ancien contrat)
    // ---------------------------------------------------------------
    public function login(Request $request)
    {
        $data = $request->validate([
            'matricule' => ['required', 'string'],
            'password' => ['required', 'string'],
            'role' => ['nullable', 'string'],
        ]);

        $user = User::with(['roles', 'agent.structure', 'agent.fonction'])
            ->whereRaw('UPPER(matricule) = ?', [strtoupper(trim($data['matricule']))])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['status' => 'error', 'message' => 'Matricule ou mot de passe incorrect.'], 401);
        }

        $codes = $user->roles->pluck('code')->all();
        $requested = strtoupper(trim($data['role'] ?? ''));

        if ($requested !== '' && isset(self::PROFILE_ROLES[$requested])) {
            if (empty(array_intersect($codes, self::PROFILE_ROLES[$requested]))) {
                return response()->json(['status' => 'error', 'message' => 'Profil non autorisé pour ce compte.'], 403);
            }
            $profile = $requested;
        } else {
            $profile = $this->primaryProfile($codes);
        }

        $user->update(['derniere_connexion' => now()]);
        $token = $user->createToken('gfp')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'user' => $this->legacyUser($user, $profile),
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'civilite' => ['required', 'string', 'max:10'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:150'],
            'matricule' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string'],
        ]);

        $matricule = strtoupper(trim($data['matricule']));
        $roleCodes = $this->resolveRoleCodes($data['role']);
        if ($roleCodes === null) {
            return response()->json(['status' => 'error', 'message' => 'Rôle non reconnu.'], 422);
        }
        if (Agent::where('matricule', $matricule)->exists() || User::where('matricule', $matricule)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Ce matricule est déjà utilisé.'], 422);
        }
        if (Agent::whereRaw('UPPER(nom) = ? AND UPPER(prenom) = ?', [strtoupper(trim($data['nom'])), strtoupper(trim($data['prenom']))])->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Cette personne est déjà enregistrée.'], 422);
        }

        $agent = Agent::create([
            'matricule' => $matricule,
            'civilite' => $data['civilite'],
            'nom' => strtoupper(trim($data['nom'])),
            'prenom' => trim($data['prenom']),
        ]);
        $user = User::create([
            'name' => $agent->fullName(),
            'email' => strtolower($matricule).'@fonctionpublique.gouv.ci',
            'matricule' => $matricule,
            'password' => $data['password'],
            'agent_id' => $agent->id,
        ]);
        $user->roles()->attach(Role::whereIn('code', $roleCodes)->pluck('id')->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Compte créé avec succès. Vous pouvez maintenant vous connecter.',
            'user' => ['matricule' => $matricule, 'role' => $data['role'], 'nom' => $agent->nom, 'prenom' => $agent->prenom, 'civilite' => $agent->civilite],
        ], 201);
    }

    // ---------------------------------------------------------------
    // Demandes & actes (ancien contrat)
    // ---------------------------------------------------------------
    public function requests()
    {
        $reqs = [];

        $perms = DemandePermission::with(['agent', 'type'])->latest('id')->get();
        foreach ($perms as $p) {
            $reqs[] = [
                'id' => $p->code_dossier,
                'nature' => 'DEMANDE_PERMISSION',
                'agentName' => $p->agent->fullName(),
                'matricule' => $p->agent->matricule,
                'typePerm' => $p->type?->libelle,
                'motif' => $p->motif,
                'dateDebut' => $p->date_debut?->format('Y-m-d'),
                'dateFin' => $p->date_fin?->format('Y-m-d'),
                'dateSoumission' => $p->created_at?->format('Y-m-d H:i:s'),
                'dateAvisN1' => $p->date_verif_rh?->format('Y-m-d H:i:s'),
                'dateDecisionFinale' => $p->date_decision?->format('Y-m-d H:i:s'),
                'jours' => $p->nombre_jours,
                'piece' => $p->piece_path ?? 'Justificatif_Permission.pdf',
                'avis_n1' => $p->avis_gestionnaire,
                'decision_finale' => $p->decision_drh,
                'niveau_requis' => $p->nombre_jours <= 2 ? 'DIRECTION' : 'DRH',
                'statut' => $this->mapPermissionStatus($p->statut),
                'etape' => $p->statut,
                'notifie' => $p->notifie_le !== null,
            ];
        }

        foreach (DeclarationNaissance::with('agent')->latest('id')->get() as $n) {
            $reqs[] = [
                'id' => $n->code_dossier_naiss ?? $n->code_dossier,
                'nature' => 'DECLARATION_NAISSANCE',
                'agentName' => $n->agent->fullName(),
                'matricule' => $n->agent->matricule,
                'nomChild' => trim($n->nom_enfant.' '.$n->prenom_enfant),
                'dateEvt' => $n->date_naissance_enfant?->format('Y-m-d'),
                'dateSoumission' => $n->created_at?->format('Y-m-d H:i:s'),
                'dateValidation' => $n->validated_at?->format('Y-m-d H:i:s'),
                'lieu' => $n->lieu_naissance_enfant,
                'piece' => $n->extrait_path ? basename($n->extrait_path) : 'Extrait_Acte_Naissance.pdf',
                'statut' => $this->mapEtatCivilStatus($n->statut),
                'etape' => $n->statut,
                'motif_rejet' => $n->motif_rejet,
                'motif_retour' => $n->motif_retour,
            ];
        }

        foreach (DeclarationDeces::with('agent')->latest('id')->get() as $d) {
            $reqs[] = [
                'id' => $d->code_dossier_deces ?? $d->code_dossier,
                'nature' => 'DECLARATION_DECES',
                'agentName' => $d->agent->fullName(),
                'matricule' => $d->agent->matricule,
                'nomDefunt' => trim($d->nom_defunt.' '.$d->prenom_defunt).' ('.$d->lien_parente.')',
                'dateEvt' => $d->date_deces?->format('Y-m-d'),
                'dateSoumission' => $d->created_at?->format('Y-m-d H:i:s'),
                'dateValidation' => $d->validated_at?->format('Y-m-d H:i:s'),
                'lieu' => $d->lieu_deces,
                'piece' => $d->certificat_path ? basename($d->certificat_path) : 'Certificat_Deces.pdf',
                'statut' => $this->mapEtatCivilStatus($d->statut),
                'etape' => $d->statut,
                'motif_rejet' => $d->motif_rejet,
                'motif_retour' => $d->motif_retour,
            ];
        }

        return response()->json(['status' => 'success', 'requests' => $reqs]);
    }

    public function submitPermission(Request $request)
    {
        // Nouveau contrat (fichier éventuel).
        if ($request->has('type_permission_id')) {
            $data = $request->validate([
                'type_permission_id' => ['required', 'exists:types_permission,id'],
                'date_debut' => ['required', 'date'],
                'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
                'motif' => ['required', 'string', 'min:5'],
                'piece' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            ]);
            $path = $request->hasFile('piece') ? $request->file('piece')->store('permissions', 'public') : null;
            $demande = PermissionWorkflowService::soumettre($request->user()->agent, [
                'type_permission_id' => $data['type_permission_id'],
                'date_debut' => $data['date_debut'],
                'date_fin' => $data['date_fin'],
                'motif' => $data['motif'],
                'piece_path' => $path,
            ]);

            return response()->json(['status' => 'success', 'data' => $demande], 201);
        }

        // Ancien contrat JSON.
        $data = $request->validate([
            'typePerm' => ['nullable', 'string'],
            'motif' => ['required', 'string', 'min:3'],
            'dateDebut' => ['required', 'date'],
            'dateFin' => ['required', 'date', 'after_or_equal:dateDebut'],
            'jours' => ['nullable', 'integer', 'min:1', 'max:30'],
            'piece' => ['nullable', 'string'],
        ]);

        $type = ! empty($data['typePerm'])
            ? TypePermission::where('libelle', 'like', '%'.trim($data['typePerm']).'%')->first()
            : null;
        $type ??= TypePermission::firstOrFail();

        $demande = PermissionWorkflowService::soumettre($request->user()->agent, [
            'type_permission_id' => $type->id,
            'date_debut' => $data['dateDebut'],
            'date_fin' => $data['dateFin'],
            'motif' => $data['motif'],
            'piece_path' => $data['piece'] ?? 'Justificatif.pdf',
            'nombre_jours' => $data['jours'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'code' => $demande->code_dossier,
            'jours' => $demande->nombre_jours,
            'niveau_requis' => $demande->nombre_jours <= 2 ? 'DIRECTION' : 'DRH',
        ], 201);
    }

    public function submitDeclaration(Request $request)
    {
        $data = $request->validate([
            'nature' => ['required', 'in:NAISSANCE,DECES'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:150'],
            'date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $type = $data['nature'];
        $agent = $request->user()->agent;

        if ($type === 'NAISSANCE') {
            $piece = $request->hasFile('extrait')
                ? PieceService::deposer($request->file('extrait'), 'naissance', null, null, $agent)
                : null;
            $declaration = EtatCivilService::declarer('NAISSANCE', $agent, [
                'nom_enfant' => $data['nom'],
                'prenom_enfant' => $data['prenom'],
                'date_naissance_enfant' => $data['date'],
                'lieu_naissance_enfant' => $request->input('lieu', 'Abidjan'),
            ], $piece?->chemin_stockage ?? 'Extrait_Acte_Naissance.pdf');
            $piece?->update(['dossier_id' => $declaration->id, 'reference_dossier' => $declaration->code_dossier]);
        } else {
            $piece = $request->hasFile('certificat')
                ? PieceService::deposer($request->file('certificat'), 'deces', null, null, $agent)
                : null;
            $declaration = EtatCivilService::declarer('DECES', $agent, [
                'nom_defunt' => $data['nom'],
                'prenom_defunt' => $data['prenom'],
                'lien_parente' => $request->input('lien_parente', 'ascendant'),
                'date_deces' => $data['date'],
                'lieu_deces' => $request->input('lieu', 'Abidjan'),
            ], $piece?->chemin_stockage ?? 'Certificat_Deces.pdf');
            $piece?->update(['dossier_id' => $declaration->id, 'reference_dossier' => $declaration->code_dossier]);
        }

        return response()->json(['status' => 'success', 'code' => $declaration->code_dossier, 'data' => $declaration], 201);
    }

    /**
     * Ancien contrat POST /api/status {id, statut} branché sur le workflow :
     * EN_ATTENTE_RH (contrat historique) = avis favorable / étape suivante, VALIDEE = décision
     * finale DRH, REJETEE = refus (motif générique enregistré et notifié).
     */
    public function updateStatus(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'string'],
            'statut' => ['required', 'in:EN_ATTENTE_RH,VALIDEE,REJETEE'],
        ]);

        $user = $request->user();
        $agent = $user->agent;
        $code = strtoupper(trim($data['id']));
        $target = $data['statut'];

        if (str_starts_with($code, 'PERM')) {
            $demande = DemandePermission::where('code_dossier', $code)->first();
            if (! $demande) {
                return response()->json(['status' => 'error', 'message' => 'Dossier introuvable.'], 404);
            }

            if ($target === 'EN_ATTENTE_RH') {
                if ($demande->statut === DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH) {
                    if (! $user->hasRole('ROLE_GESTIONNAIRE_RH')) {
                        return response()->json(['status' => 'error', 'message' => 'Vérification RH requise.'], 403);
                    }
                    PermissionWorkflowService::verifierRh($demande, $agent, 'conforme');
                } elseif (in_array($demande->statut, [DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR, DemandePermission::EN_ATTENTE_VISA_DIRECTEUR], true)) {
                    $role = $demande->statut === DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR ? 'SOUS_DIRECTEUR' : 'DIRECTEUR';
                    if (! $user->hasRole('ROLE_'.strtoupper($role))) {
                        return response()->json(['status' => 'error', 'message' => 'Visa '.$role.' requis.'], 403);
                    }
                    PermissionWorkflowService::viser($demande, $agent, $role, true);
                } else {
                    return response()->json(['status' => 'error', 'message' => 'Transition impossible à ce stade.'], 422);
                }
            } elseif ($target === 'VALIDEE') {
                if (! $user->hasRole('ROLE_DRH')) {
                    return response()->json(['status' => 'error', 'message' => 'Décision DRH requise.'], 403);
                }
                PermissionWorkflowService::trancherDrh($demande, $agent, true);
            } else {
                $this->rejeterPermission($demande, $agent, $user);
            }

            return response()->json(['status' => 'success']);
        }

        if (str_starts_with($code, 'NAISS')) {
            return $this->validerActe(DeclarationNaissance::where('code_dossier', $code)->first(), $agent, $user, $target, $code, 'NAISSANCE');
        }

        if (str_starts_with($code, 'DECES')) {
            return $this->validerActe(DeclarationDeces::where('code_dossier', $code)->first(), $agent, $user, $target, $code, 'DECES');
        }

        return response()->json(['status' => 'error', 'message' => 'Dossier introuvable.'], 404);
    }

    // ---------------------------------------------------------------
    // Notes de service (ancien + nouveau contrat)
    // ---------------------------------------------------------------
    public function notes(Request $request)
    {
        $user = $request->user();
        $query = NoteService::with(['structures', 'signataire'])->latest('id');

        if ($user->hasRole('ROLE_AGENT', 'ROLE_GESTIONNAIRE_RH') && ! $user->hasRole('ROLE_ADMIN_DSI', 'ROLE_DRH', 'ROLE_DIRECTEUR', 'ROLE_SOUS_DIRECTEUR', 'ROLE_SECRETAIRE')) {
            $query->whereIn('statut', [NoteService::DIFFUSEE, NoteService::ARCHIVEE])
                ->whereHas('structures', fn ($q) => $q->where('structures.id', $user->agent->structure_id));
        }

        $notes = $query->get()->map(fn (NoteService $n) => [
            'id' => $n->id,
            'numero' => $n->numero_reference,
            'libelle' => $n->objet,
            'date' => $n->date_emission?->format('Y-m-d'),
            'service' => $n->structures->pluck('nom')->join(', ') ?: 'Tous les Services du Ministère',
            'emetteur' => $n->signataire?->fullName() ?? 'Direction',
            'secretaire' => 'Secrétariat',
            'statut' => $this->mapNoteStatus($n->statut),
            'destinataires_detail' => 'Transmis aux structures : '.($n->structures->pluck('nom')->join(', ') ?: 'Tous les Services du Ministère'),
        ])->all();

        return response()->json([
            'status' => 'success',
            'notes' => $notes,
            'data' => NoteService::with(['structures'])->latest('id')->paginate(20),
        ]);
    }

    public function storeNote(Request $request)
    {
        $action = strtolower((string) $request->input('action', ''));

        // Saisie / mise en forme par le secrétariat (ancien contrat).
        if ($action === 'saisir') {
            abort_unless($request->user()->hasRole('ROLE_SECRETAIRE'), 403, 'Saisie réservée au secrétariat.');
            $note = NoteService::find($request->input('note_id'));
            if (! $note) {
                return response()->json(['status' => 'error', 'message' => 'Note introuvable.'], 404);
            }
            $note = NoteWorkflowService::saisir($note, $request->user()->agent, [
                'contenu' => $request->input('contenu', $note->contenu ?? 'Note mise en forme par le secrétariat.'),
            ]);

            return response()->json(['status' => 'success', 'ref' => $note->numero_reference, 'statut' => $note->statut]);
        }

        // Validation par l'autorité émettrice (ancien contrat).
        if ($action === 'valider') {
            $note = NoteService::find($request->input('note_id'));
            if (! $note) {
                return response()->json(['status' => 'error', 'message' => 'Note introuvable.'], 404);
            }
            $note = NoteWorkflowService::valider($note, $request->user()->agent);

            return response()->json(['status' => 'success', 'ref' => $note->numero_reference, 'statut' => $note->statut]);
        }

        // Diffusion par le secrétariat, note validée uniquement (ancien contrat).
        if ($action === 'diffuse') {
            abort_unless($request->user()->hasRole('ROLE_SECRETAIRE'), 403, 'Diffusion réservée au secrétariat.');
            $note = NoteService::find($request->input('note_id'));
            if (! $note || in_array($note->statut, [NoteService::DIFFUSEE, NoteService::ARCHIVEE], true)) {
                return response()->json(['status' => 'error', 'message' => 'Note introuvable ou déjà diffusée.'], 422);
            }
            if ($note->statut === NoteService::EN_ATTENTE_SAISIE) {
                $note = NoteWorkflowService::saisir($note, $request->user()->agent, [
                    'contenu' => $note->contenu ?? 'Note mise en forme par le secrétariat.',
                ]);
            }
            if ($note->statut !== NoteService::VALIDEE) {
                return response()->json(['status' => 'error', 'message' => 'Note non validée par l’autorité : diffusion impossible.'], 422);
            }
            $note = NoteWorkflowService::diffuser($note, $request->user()->agent);

            return response()->json(['status' => 'success', 'ref' => $note->numero_reference]);
        }

        // Nouveau contrat (objet) : rédaction + transmission au secrétariat.
        if ($request->has('objet')) {
            abort_unless($request->user()->hasRole('ROLE_DRH', 'ROLE_DIRECTEUR', 'ROLE_SOUS_DIRECTEUR', 'ROLE_CHEF_DE_SERVICE', 'ROLE_DIRECTEUR_CABINET'), 403, 'Émission réservée à une autorité habilitée.');
            $data = $request->validate([
                'objet' => ['required', 'string', 'min:5'],
                'contenu' => ['nullable', 'string'],
                'structure_ids' => ['nullable', 'array'],
                'structure_ids.*' => ['exists:structures,id'],
            ]);
            $note = NoteWorkflowService::rediger($request->user()->agent, $data, null);
            $note = NoteWorkflowService::transmettreSecretariat($note, $request->user()->agent);

            return response()->json(['status' => 'success', 'ref' => $note->numero_reference, 'note_id' => $note->id, 'statut' => $note->statut, 'data' => $note->load('structures')], 201);
        }

        // Ancien contrat : émission (titre + structures) puis secrétariat.
        abort_unless($request->user()->hasRole('ROLE_DRH', 'ROLE_DIRECTEUR', 'ROLE_SOUS_DIRECTEUR', 'ROLE_CHEF_DE_SERVICE', 'ROLE_DIRECTEUR_CABINET'), 403, 'Émission réservée à une autorité habilitée.');
        $data = $request->validate([
            'title' => ['required', 'string', 'min:5'],
            'recipient_structure_ids' => ['nullable'],
        ]);
        $note = NoteWorkflowService::rediger($request->user()->agent, [
            'objet' => $data['title'],
            'structure_ids' => $data['recipient_structure_ids'] ?? null,
        ], null);
        $note = NoteWorkflowService::transmettreSecretariat($note, $request->user()->agent);

        return response()->json(['status' => 'success', 'ref' => $note->numero_reference, 'note_id' => $note->id, 'statut' => $note->statut], 201);
    }

    private function mapEtatCivilStatus(string $statut): string
    {
        return match ($statut) {
            EtatCivilService::EN_ATTENTE_SERVICE => 'EN_ATTENTE_SERVICE',
            default => $statut,
        };
    }

    private function mapNoteStatus(string $statut): string
    {
        return match ($statut) {
            NoteService::DIFFUSEE => 'DIFFUSEE',
            NoteService::ARCHIVEE => 'ARCHIVEE',
            default => 'A_SAISIR',
        };
    }

    // ---------------------------------------------------------------
    // Référentiels & utilisateurs (ancien contrat)
    // ---------------------------------------------------------------
    public function structures()
    {
        $structures = Structure::orderBy('id')->get()->map(fn (Structure $s) => [
            'id_structure' => $s->id,
            'code_structure' => $s->code,
            'nom_structure' => $s->nom,
            'type_structure' => $s->type,
        ]);

        return response()->json(['status' => 'success', 'structures' => $structures]);
    }

    public function roles()
    {
        return response()->json(['status' => 'success', 'roles' => Role::orderBy('id')->get()]);
    }

    public function users()
    {
        $users = User::with(['roles', 'agent.structure', 'agent.fonction'])->orderBy('id')->get()->map(function (User $u) {
            $code = $u->roles->first()?->code;

            return [
                'id_agent' => $u->agent?->id,
                'id_utilisateur' => $u->id,
                'matricule' => $u->matricule,
                'civilite' => $u->agent?->civilite ?? 'M.',
                'nom' => $u->agent?->nom ?? $u->name,
                'prenom' => $u->agent?->prenom ?? '',
                'structure' => $u->agent?->structure?->nom ?? 'Non assigné',
                'fonction' => $u->agent?->fonction?->libelle ?? 'Fonctionnaire',
                'solde' => $u->agent?->solde_permission_annuel ?? 30,
                'telephone' => $u->agent?->telephone,
                'email' => $u->agent?->email,
                'login' => $u->matricule,
                'password' => '',
                'statut' => 'ACTIF',
                'roles' => $u->roles->map(fn ($r) => ['code_role' => $r->code, 'libelle_role' => $r->libelle])->all(),
                'role' => $code ? (self::NEW_TO_LEGACY_CODE[$code] ?? 'ROLE_AGENT') : 'ROLE_AGENT',
            ];
        });

        return response()->json(['status' => 'success', 'users' => $users]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'matricule' => ['required', 'string', 'max:30'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:150'],
            'role' => ['required', 'string'],
            'password' => ['required', 'string', 'min:4'],
            'structure' => ['nullable', 'string'],
            'civilite' => ['nullable', 'string', 'max:10'],
            'telephone' => ['nullable', 'string'],
        ]);

        $roleCodes = $this->resolveRoleCodes($data['role']);
        if ($roleCodes === null) {
            return response()->json(['status' => 'error', 'message' => 'Rôle non reconnu.'], 422);
        }

        $matricule = strtoupper(trim($data['matricule']));
        if (Agent::where('matricule', $matricule)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Ce matricule est déjà utilisé.'], 422);
        }

        $structure = ! empty($data['structure'])
            ? Structure::where('nom', 'like', '%'.trim($data['structure']).'%')->first()
            : null;

        $agent = Agent::create([
            'matricule' => $matricule,
            'civilite' => $data['civilite'] ?? 'M.',
            'nom' => strtoupper(trim($data['nom'])),
            'prenom' => trim($data['prenom']),
            'telephone' => $data['telephone'] ?? null,
            'structure_id' => $structure?->id,
        ]);
        $user = User::create([
            'name' => $agent->fullName(),
            'email' => strtolower($matricule).'@fonctionpublique.gouv.ci',
            'matricule' => $matricule,
            'password' => $data['password'],
            'agent_id' => $agent->id,
            'structure_id' => $agent->structure_id,
        ]);
        $user->roles()->attach(Role::whereIn('code', $roleCodes)->pluck('id')->all());

        return response()->json(['status' => 'success', 'message' => 'Compte créé.'], 201);
    }

    public function updateUser(Request $request)
    {
        $data = $request->validate([
            'matricule' => ['required', 'string'],
            'password' => ['nullable', 'string'],
            'role' => ['required', 'string'],
        ]);

        $user = User::where('matricule', strtoupper(trim($data['matricule'])))->first();
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Agent introuvable.'], 404);
        }

        $roleCodes = $this->resolveRoleCodes($data['role']);
        if ($roleCodes === null) {
            return response()->json(['status' => 'error', 'message' => 'Rôle non reconnu.'], 422);
        }

        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->save();
        }
        $user->roles()->sync(Role::whereIn('code', $roleCodes)->pluck('id')->all());

        return response()->json(['status' => 'success', 'message' => 'Compte utilisateur mis à jour.']);
    }

    // ---------------------------------------------------------------
    // Notifications (ancien contrat)
    // ---------------------------------------------------------------
    public function notifications(Request $request)
    {
        $agentId = $request->query('agent_id') ?? $request->user()->agent_id;
        $query = Notification::where('agent_id', $agentId)->latest('id');
        $rows = (clone $query)->limit(30)->get()->map(fn (Notification $n) => [
            'id_notification' => $n->id,
            'id_agent_destinataire' => $n->agent_id,
            'titre_notif' => $n->titre,
            'message_notif' => $n->message,
            'type_notif' => $n->type,
            'reference_dossier' => $n->reference_dossier,
            'est_lu' => (bool) $n->est_lu,
            'date_creation' => $n->created_at?->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'status' => 'success',
            'notifications' => $rows,
            'unread_count' => (clone $query)->where('est_lu', false)->count(),
            'data' => Notification::where('agent_id', $agentId)->latest('id')->paginate(30),
            'unread' => (clone $query)->where('est_lu', false)->count(),
        ]);
    }

    public function markNotificationsRead(Request $request)
    {
        $agentId = $request->input('agent_id') ?? $request->user()->agent_id;
        Notification::where('agent_id', $agentId)->update(['est_lu' => true]);

        return response()->json(['status' => 'success', 'message' => 'Notifications marquées comme lues.']);
    }

    /**
     * Notification explicite de l'agent par le Gestionnaire RH
     * après décision du DRH (ancien contrat, par code dossier).
     */
    public function notifier(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'string']]);
        abort_unless($request->user()->hasRole('ROLE_GESTIONNAIRE_RH'), 403, 'Notification réservée au Gestionnaire RH.');

        $demande = DemandePermission::where('code_dossier', strtoupper(trim($data['id'])))->first();
        if (! $demande) {
            return response()->json(['status' => 'error', 'message' => 'Dossier introuvable.'], 404);
        }

        PermissionWorkflowService::notifierAgent($demande, $request->user()->agent);

        return response()->json(['status' => 'success']);
    }

    // ---------------------------------------------------------------
    // Helpers privés
    // ---------------------------------------------------------------
    private function primaryProfile(array $codes): string
    {
        foreach ([
            'ROLE_ADMIN_DSI' => 'ADMINISTRATEUR',
            'ROLE_DRH' => 'DRH',
            'ROLE_DIRECTEUR' => 'DIRECTEUR',
            'ROLE_SOUS_DIRECTEUR' => 'SOUS_DIRECTEUR',
            'ROLE_GESTIONNAIRE_RH' => 'RESPONSABLE',
            'ROLE_SERVICE_ADMINISTRATIF' => 'SERVICE',
            'ROLE_CHEF_DE_SERVICE' => 'CHEF_SERVICE',
            'ROLE_DIRECTEUR_CABINET' => 'DIRCAB',
            'ROLE_SECRETAIRE' => 'SECRETAIRE',
        ] as $code => $profile) {
            if (in_array($code, $codes, true)) {
                return $profile;
            }
        }

        return 'AGENT';
    }

    private function legacyUser(User $user, string $profile): array
    {
        $agent = $user->agent;

        return [
            'id_utilisateur' => $user->id,
            'id_agent' => $agent?->id,
            'matricule' => $user->matricule,
            'name' => $user->name,
            'civilite' => $agent?->civilite,
            'nom' => $agent?->nom,
            'prenom' => $agent?->prenom,
            'structure' => $agent?->structure?->nom,
            'code_structure' => $agent?->structure?->code,
            'fonction' => $agent?->fonction?->libelle,
            'solde_permission' => $agent?->solde_permission_annuel,
            'role' => $profile,
            'roles_codes' => $user->roles->pluck('code')->all(),
            'roles_labels' => $user->roles->pluck('libelle')->all(),
            'privileges' => $user->roles->flatMap->privileges->pluck('code')->unique()->values()->all(),
            'unread_notifs' => $agent ? Notification::where('agent_id', $agent->id)->where('est_lu', false)->count() : 0,
        ];
    }

    private function mapPermissionStatus(string $statut): string
    {
        return match ($statut) {
            DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH,
            DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR,
            DemandePermission::EN_ATTENTE_VISA_DIRECTEUR => 'EN_ATTENTE_N1',
            DemandePermission::EN_ATTENTE_DRH => 'EN_ATTENTE_RH',
            default => $statut,
        };
    }

    private function rejeterPermission(DemandePermission $demande, Agent $agent, User $user): void
    {
        $motif = 'Avis défavorable hiérarchique.';
        if ($demande->statut === DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH) {
            abort_unless($user->hasRole('ROLE_GESTIONNAIRE_RH'), 403, 'Vérification RH requise.');
            PermissionWorkflowService::verifierRh($demande, $agent, 'rejeter', $motif);
        } elseif (in_array($demande->statut, [DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR, DemandePermission::EN_ATTENTE_VISA_DIRECTEUR], true)) {
            $role = $demande->statut === DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR ? 'SOUS_DIRECTEUR' : 'DIRECTEUR';
            abort_unless($user->hasRole('ROLE_'.strtoupper($role)), 403, 'Visa '.$role.' requis.');
            PermissionWorkflowService::viser($demande, $agent, $role, false, $motif);
        } else {
            abort_unless($user->hasRole('ROLE_DRH'), 403, 'Décision DRH requise.');
            PermissionWorkflowService::trancherDrh($demande, $agent, false, 'Rejet DRH : dossier non conforme.');
        }
    }

    /**
     * Ancien contrat actes : le service administratif contrôle d'abord
     * (EN_ATTENTE_RH = conforme), la DRH tranche ensuite.
     */
    private function validerActe(mixed $acte, Agent $agent, User $user, string $target, string $code, string $type)
    {
        if (! $acte) {
            return response()->json(['status' => 'error', 'message' => 'Dossier introuvable.'], 404);
        }

        if ($acte->statut === EtatCivilService::EN_ATTENTE_SERVICE) {
            if ($user->hasRole('ROLE_DRH')) {
                // RG24 : La DRH contrôle et valide directement les déclarations
                EtatCivilService::controler($type, $acte, $agent, 'conforme');
                EtatCivilService::trancher($type, $acte, $agent, $target === 'VALIDEE',
                    $target === 'VALIDEE' ? null : 'Déclaration rejetée par la DRH : pièce non conforme.');

                return response()->json(['status' => 'success']);
            }

            abort_unless($user->hasRole('ROLE_SERVICE_ADMINISTRATIF'), 403, 'Contrôle du service administratif requis.');
            if ($target === 'EN_ATTENTE_RH') {
                EtatCivilService::controler($type, $acte, $agent, 'conforme');
            } else {
                EtatCivilService::controler($type, $acte, $agent, 'retourner', 'Dossier incomplet : pièce ou information manquante.');
            }

            return response()->json(['status' => 'success']);
        }

        if ($acte->statut === EtatCivilService::EN_ATTENTE_RH) {
            abort_unless($user->hasRole('ROLE_DRH'), 403, 'Validation DRH requise.');
            EtatCivilService::trancher($type, $acte, $agent, $target === 'VALIDEE',
                $target === 'VALIDEE' ? null : 'Déclaration rejetée par la DRH : pièce non conforme.');

            return response()->json(['status' => 'success']);
        }

        return response()->json(['status' => 'error', 'message' => 'Transition impossible à ce stade.'], 422);
    }
}
