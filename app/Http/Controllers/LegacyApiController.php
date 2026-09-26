<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\DeclarationDeces;
use App\Models\DeclarationNaissance;
use App\Models\DemandePermission;
use App\Models\JournalAudit;
use App\Models\NoteService;
use App\Models\Notification;
use App\Models\PieceJointe;
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
use Illuminate\Support\Str;

/**
 * Couche de compatibilité avec le frontend historique (frontend/, servi tel quel
 * depuis public/gfp). Expose l'ancien contrat /api/* adossé aux nouveaux
 * modèles et au workflow Cas 1 (≤ 2 j) / Cas 2 (> 2 j).
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
        'DIRCAB' => ['ROLE_DIRECTEUR_CABINET'],
        'SECRETAIRE' => ['ROLE_SECRETAIRE'],
        'SERVICE' => ['ROLE_SERVICE_ADMINISTRATIF'],
        'CHEF_SERVICE' => ['ROLE_CHEF_DE_SERVICE'],
        'ADMINISTRATEUR' => ['ROLE_ADMIN_DSI'],
    ];

    /** Rôle simple (admin) -> nouveaux codes. RESPONSABLE = Gestionnaire RH seul (séparation vérification / visa). */
    private const SIMPLE_ROLES = [
        'AGENT' => ['ROLE_AGENT'],
        'RESPONSABLE' => ['ROLE_GESTIONNAIRE_RH'],
        'DRH' => ['ROLE_DRH'],
        'ADMINISTRATEUR' => ['ROLE_ADMIN_DSI'],
    ];

    /**
     * Traduit le rôle reçu (profil simple, ancien code ou nouveau code) en codes de rôles réels.
     * Renvoie null si le rôle n'existe pas.
     */
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
        'ROLE_CHEF_SERVICE' => ['ROLE_GESTIONNAIRE_RH'],
        'ROLE_SOUS_DIRECTEUR' => ['ROLE_SOUS_DIRECTEUR'],
        'ROLE_DIRECTEUR' => ['ROLE_DIRECTEUR'],
        'ROLE_DIRCAB' => ['ROLE_DIRECTEUR_CABINET'],
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
        'ROLE_DIRECTEUR_CABINET' => 'ROLE_DIRCAB',
        'ROLE_CHEF_DE_SERVICE' => 'ROLE_CHEF_DE_SERVICE',
        'ROLE_SERVICE_ADMINISTRATIF' => 'ROLE_SERVICE_ADMINISTRATIF',
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
            JournalAudit::noter(
                JournalAudit::CONNEXION,
                'CONNEXION_REFUSEE',
                $user,
                $user ? 'Mot de passe incorrect' : 'Matricule inconnu',
                null,
                false,
                $data['matricule'],
            );

            return response()->json(
                ['status' => 'error', 'message' => 'Matricule ou mot de passe incorrect.'],
                401,
            );
        }

        if (! $user->actif) {
            JournalAudit::noter(
                JournalAudit::CONNEXION,
                'CONNEXION_REFUSEE',
                $user,
                'Compte suspendu',
                null,
                false,
            );

            return response()->json(
                [
                    'status' => 'error',
                    'message' => 'Ce compte est suspendu. Contactez l’administrateur.',
                ],
                403,
            );
        }

        $codes = $user->roles->pluck('code')->all();
        $requested = strtoupper(trim($data['role'] ?? ''));

        if ($requested !== '' && isset(self::PROFILE_ROLES[$requested])) {
            if (empty(array_intersect($codes, self::PROFILE_ROLES[$requested]))) {
                JournalAudit::noter(
                    JournalAudit::CONNEXION,
                    'CONNEXION_REFUSEE',
                    $user,
                    "Profil non autorisé : {$requested}",
                    null,
                    false,
                );

                return response()->json(
                    ['status' => 'error', 'message' => 'Profil non autorisé pour ce compte.'],
                    403,
                );
            }
            $profile = $requested;
        } else {
            $profile = $this->primaryProfile($codes);
        }

        $user->update(['derniere_connexion' => now()]);
        $token = $user->createToken('gfp')->plainTextToken;
        JournalAudit::noter(
            JournalAudit::CONNEXION,
            'CONNEXION',
            $user,
            "Connexion (profil {$profile})",
        );

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'user' => $this->legacyUser($user, $profile),
        ]);
    }

    /**
     * Inscription publique : l'agent choisit son rôle (jamais administrateur), la structure est
     * facultative ; refus si le matricule ou la personne existent déjà.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'civilite' => ['required', 'string', 'max:10'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:150'],
            'matricule' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string'],
            'structure_id' => ['nullable', 'exists:structures,id'],
        ]);

        // L'agent choisit son rôle à l'inscription ; l'administration reste attribuée par l'administrateur.
        $matricule = strtoupper(trim($data['matricule']));
        $roleCodes = $this->resolveRoleCodes($data['role']);
        if (
            $roleCodes === null ||
            array_intersect($roleCodes, ['ROLE_ADMIN_DSI', 'ROLE_SERVICE_ADMINISTRATIF'])
        ) {
            return response()->json(
                ['status' => 'error', 'message' => 'Rôle non disponible à l’inscription.'],
                422,
            );
        }
        if (
            Agent::where('matricule', $matricule)->exists() ||
            User::where('matricule', $matricule)->exists()
        ) {
            return response()->json(
                ['status' => 'error', 'message' => 'Ce matricule est déjà utilisé.'],
                422,
            );
        }
        if (
            Agent::whereRaw('UPPER(nom) = ? AND UPPER(prenom) = ?', [
                strtoupper(trim($data['nom'])),
                strtoupper(trim($data['prenom'])),
            ])->exists()
        ) {
            return response()->json(
                ['status' => 'error', 'message' => 'Cette personne est déjà enregistrée.'],
                422,
            );
        }

        $agent = Agent::create([
            'matricule' => $matricule,
            'civilite' => $data['civilite'],
            'nom' => strtoupper(trim($data['nom'])),
            'prenom' => trim($data['prenom']),
            'structure_id' => $data['structure_id'] ?? null,
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
        JournalAudit::noter(
            JournalAudit::COMPTE,
            'INSCRIPTION',
            $user,
            'Auto-inscription : '.implode(', ', $roleCodes),
        );

        return response()->json(
            [
                'status' => 'success',
                'message' => 'Compte créé avec succès. Vous pouvez maintenant vous connecter.',
                'user' => [
                    'matricule' => $matricule,
                    'role' => $data['role'],
                    'nom' => $agent->nom,
                    'prenom' => $agent->prenom,
                    'civilite' => $agent->civilite,
                ],
            ],
            201,
        );
    }

    // ---------------------------------------------------------------
    // Demandes & actes (ancien contrat)
    // ---------------------------------------------------------------
    public function requests(Request $request)
    {
        $user = $request->user();
        $reqs = [];

        $perms = DemandePermission::with(['agent', 'type'])->latest('id');
        if (! $user->hasRole(...PermissionWorkflowService::ROLES_SUIVI)) {
            $perms->where('agent_id', $user->agent_id);
        }
        $naissances = DeclarationNaissance::with('agent')->latest('id');
        $deces = DeclarationDeces::with('agent')->latest('id');
        if (! $user->hasRole(...EtatCivilService::ROLES_SUIVI)) {
            $naissances->where('agent_id', $user->agent_id);
            $deces->where('agent_id', $user->agent_id);
        }
        [$perms, $naissances, $deces] = [$perms->get(), $naissances->get(), $deces->get()];

        // Pièces réellement déposées (téléchargement contrôlé via /api/pieces/{id}).
        $pieces = PieceJointe::whereIn(
            'chemin_stockage',
            $perms
                ->pluck('piece_path')
                ->merge($naissances->pluck('extrait_path'))
                ->merge($deces->pluck('certificat_path'))
                ->filter(),
        )->pluck('id', 'chemin_stockage');
        $pieceId = fn (?string $chemin): ?int => $chemin ? $pieces[$chemin] ?? null : null;

        foreach ($perms as $p) {
            $reqs[] = [
                'id' => $p->code_dossier,
                'dossier_id' => $p->id,
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
                'piece' => $p->piece_path ? basename($p->piece_path) : 'Aucune pièce jointe',
                'piece_id' => $pieceId($p->piece_path),
                'avis_n1' => $p->avis_gestionnaire,
                'decision_finale' => $p->decision_drh,
                'niveau_requis' => $p->nombre_jours <= 2 ? 'DIRECTION' : 'DRH',
                'statut' => $this->mapPermissionStatus($p->statut),
                'etape' => $p->statut,
                'visa_attendu' => $p->visa_attendu,
                'notifie' => $p->notifie_le !== null,
                'motif_rejet' => $p->motif_rejet,
                'motif_retour' => $p->motif_retour,
            ];
        }

        foreach ($naissances as $n) {
            $reqs[] = [
                'id' => $n->code_dossier,
                'dossier_id' => $n->id,
                'nature' => 'DECLARATION_NAISSANCE',
                'nom' => $n->nom_enfant,
                'prenom' => $n->prenom_enfant,
                'agentName' => $n->agent->fullName(),
                'matricule' => $n->agent->matricule,
                'nomChild' => trim($n->nom_enfant.' '.$n->prenom_enfant),
                'dateEvt' => $n->date_naissance_enfant?->format('Y-m-d'),
                'dateSoumission' => $n->created_at?->format('Y-m-d H:i:s'),
                'dateValidation' => $n->validated_at?->format('Y-m-d H:i:s'),
                'lieu' => $n->lieu_naissance_enfant,
                'piece' => $n->extrait_path ? basename($n->extrait_path) : 'Aucune pièce jointe',
                'piece_id' => $pieceId($n->extrait_path),
                'statut' => $this->mapEtatCivilStatus($n->statut),
                'etape' => $n->statut,
                'motif_rejet' => $n->motif_rejet,
                'motif_retour' => $n->motif_retour,
            ];
        }

        foreach ($deces as $d) {
            $reqs[] = [
                'id' => $d->code_dossier,
                'dossier_id' => $d->id,
                'nature' => 'DECLARATION_DECES',
                'nom' => $d->nom_defunt,
                'prenom' => $d->prenom_defunt,
                'lien_parente' => $d->lien_parente,
                'agentName' => $d->agent->fullName(),
                'matricule' => $d->agent->matricule,
                'nomDefunt' => trim($d->nom_defunt.' '.$d->prenom_defunt).' ('.$d->lien_parente.')',
                'dateEvt' => $d->date_deces?->format('Y-m-d'),
                'dateSoumission' => $d->created_at?->format('Y-m-d H:i:s'),
                'dateValidation' => $d->validated_at?->format('Y-m-d H:i:s'),
                'lieu' => $d->lieu_deces,
                'piece' => $d->certificat_path
                    ? basename($d->certificat_path)
                    : 'Aucune pièce jointe',
                'piece_id' => $pieceId($d->certificat_path),
                'statut' => $this->mapEtatCivilStatus($d->statut),
                'etape' => $d->statut,
                'motif_rejet' => $d->motif_rejet,
                'motif_retour' => $d->motif_retour,
            ];
        }

        return response()->json(['status' => 'success', 'requests' => $reqs]);
    }

    /**
     * Dépôt d'une demande de permission depuis l'interface : justificatif et lieu obligatoires, durée
     * de 1 à 30 jours.
     */
    public function submitPermission(Request $request)
    {
        abort_if(
            $request->user()->hasRole('ROLE_CHEF_DE_SERVICE') &&
                ! $request->user()->hasRole('ROLE_AGENT'),
            403,
            'Le Chef de service ne fait pas de demandes.',
        );
        $reglePiece = [
            'nullable',
            'file',
            'mimes:'.implode(',', PieceService::MIMES),
            'max:'.PieceService::MAX_KO,
        ];

        // Nouveau contrat (fichier éventuel).
        if ($request->has('type_permission_id')) {
            $data = $request->validate([
                'type_permission_id' => ['required', 'exists:types_permission,id'],
                'date_debut' => ['required', 'date'],
                'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
                'motif' => ['required', 'string', 'min:5'],
                'piece' => $reglePiece,
            ]);
            $demande = PermissionWorkflowService::soumettre($request->user()->agent, [
                'type_permission_id' => $data['type_permission_id'],
                'date_debut' => $data['date_debut'],
                'date_fin' => $data['date_fin'],
                'motif' => $data['motif'],
            ]);
            $this->joindrePiecePermission($request, $demande);

            return response()->json(['status' => 'success', 'data' => $demande->refresh()], 201);
        }

        // Ancien contrat : JSON, ou multipart avec le justificatif. Un nom de fichier
        // envoyé en texte n'est plus enregistré (aucun fichier ne lui correspond).
        $data = $request->validate([
            'typePerm' => ['nullable', 'string'],
            'motif' => ['required', 'string', 'min:3'],
            'dateDebut' => ['required', 'date'],
            'dateFin' => ['required', 'date', 'after_or_equal:dateDebut'],
            'jours' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.PermissionWorkflowService::JOURS_MAX,
            ],
            'piece' => $request->hasFile('piece') ? $reglePiece : ['nullable'],
        ]);

        $demande = PermissionWorkflowService::soumettre($request->user()->agent, [
            'type_permission_id' => $this->resoudreTypePermission($data['typePerm'] ?? null)->id,
            'date_debut' => $data['dateDebut'],
            'date_fin' => $data['dateFin'],
            'motif' => $data['motif'],
            'nombre_jours' => $data['jours'] ?? null,
        ]);
        $this->joindrePiecePermission($request, $demande);

        return response()->json(
            [
                'status' => 'success',
                'code' => $demande->code_dossier,
                'jours' => $demande->nombre_jours,
                'niveau_requis' => $demande->nombre_jours <= 2 ? 'DIRECTION' : 'DRH',
            ],
            201,
        );
    }

    /**
     * Mêmes exigences que /api/naissances et /api/deces : lieu réel, lien de
     * parenté restreint et pièce justificative scannée obligatoire.
     */
    public function submitDeclaration(Request $request)
    {
        abort_if(
            $request->user()->hasRole('ROLE_CHEF_DE_SERVICE') &&
                ! $request->user()->hasRole('ROLE_AGENT'),
            403,
            'Le Chef de service ne fait pas de demandes.',
        );
        $reglePiece = [
            'nullable',
            'file',
            'mimes:'.implode(',', PieceService::MIMES),
            'max:'.PieceService::MAX_KO,
        ];
        $data = $request->validate([
            'nature' => ['required', 'in:NAISSANCE,DECES'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:150'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'lieu' => ['required', 'string', 'max:255'],
            'lien_parente' => [
                'required_if:nature,DECES',
                'nullable',
                'in:ascendant,descendant,conjoint',
            ],
            'extrait' => ['required_if:nature,NAISSANCE', ...$reglePiece],
            'certificat' => ['required_if:nature,DECES', ...$reglePiece],
        ]);

        $type = $data['nature'];
        $agent = $request->user()->agent;

        if ($type === 'NAISSANCE') {
            $piece = PieceService::deposer(
                $request->file('extrait'),
                'naissance',
                null,
                null,
                $agent,
            );
            $declaration = EtatCivilService::declarer(
                'NAISSANCE',
                $agent,
                [
                    'nom_enfant' => $data['nom'],
                    'prenom_enfant' => $data['prenom'],
                    'date_naissance_enfant' => $data['date'],
                    'lieu_naissance_enfant' => $data['lieu'],
                ],
                $piece->chemin_stockage,
            );
        } else {
            $piece = PieceService::deposer(
                $request->file('certificat'),
                'deces',
                null,
                null,
                $agent,
            );
            $declaration = EtatCivilService::declarer(
                'DECES',
                $agent,
                [
                    'nom_defunt' => $data['nom'],
                    'prenom_defunt' => $data['prenom'],
                    'lien_parente' => $data['lien_parente'],
                    'date_deces' => $data['date'],
                    'lieu_deces' => $data['lieu'],
                ],
                $piece->chemin_stockage,
            );
        }
        $piece->update([
            'dossier_id' => $declaration->id,
            'reference_dossier' => $declaration->code_dossier,
        ]);

        return response()->json(
            ['status' => 'success', 'code' => $declaration->code_dossier, 'data' => $declaration],
            201,
        );
    }

    /**
     * Ancien contrat POST /api/status {id, statut, motif?} branché sur le workflow :
     * EN_ATTENTE_RH (contrat historique) = avis favorable / étape suivante, VALIDEE = décision
     * finale DRH, REJETEE = refus ou retour, avec le motif saisi (motif générique à défaut).
     */
    public function updateStatus(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'string'],
            'statut' => ['required', 'in:EN_ATTENTE_RH,VALIDEE,REJETEE'],
            'motif' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $agent = $user->agent;
        $code = strtoupper(trim($data['id']));
        $target = $data['statut'];
        $motif = $data['motif'] ?? null;

        if (str_starts_with($code, 'PERM')) {
            $demande = DemandePermission::where('code_dossier', $code)->first();
            if (! $demande) {
                return response()->json(
                    ['status' => 'error', 'message' => 'Dossier introuvable.'],
                    404,
                );
            }

            if ($target === 'EN_ATTENTE_RH') {
                if ($demande->statut === DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH) {
                    if (! $user->hasRole('ROLE_GESTIONNAIRE_RH')) {
                        return response()->json(
                            ['status' => 'error', 'message' => 'Vérification RH requise.'],
                            403,
                        );
                    }
                    PermissionWorkflowService::verifierRh($demande, $agent, 'conforme');
                } elseif (
                    in_array(
                        $demande->statut,
                        [
                            DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR,
                            DemandePermission::EN_ATTENTE_VISA_DIRECTEUR,
                        ],
                        true,
                    )
                ) {
                    $role =
                        $demande->statut === DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR
                            ? 'SOUS_DIRECTEUR'
                            : 'DIRECTEUR';
                    if (! $user->hasRole('ROLE_'.strtoupper($role))) {
                        return response()->json(
                            ['status' => 'error', 'message' => 'Visa '.$role.' requis.'],
                            403,
                        );
                    }
                    PermissionWorkflowService::viser($demande, $agent, $role, true);
                } else {
                    return response()->json(
                        ['status' => 'error', 'message' => 'Transition impossible à ce stade.'],
                        422,
                    );
                }
            } elseif ($target === 'VALIDEE') {
                if (! $user->hasRole('ROLE_DRH')) {
                    return response()->json(
                        ['status' => 'error', 'message' => 'Décision DRH requise.'],
                        403,
                    );
                }
                PermissionWorkflowService::trancherDrh($demande, $agent, true);
            } else {
                $this->rejeterPermission($demande, $agent, $user, $motif);
            }

            return response()->json(['status' => 'success']);
        }

        if (str_starts_with($code, 'NAISS')) {
            return $this->validerActe(
                DeclarationNaissance::where('code_dossier', $code)->first(),
                $agent,
                $user,
                $target,
                'NAISSANCE',
                $motif,
            );
        }

        if (str_starts_with($code, 'DECES')) {
            return $this->validerActe(
                DeclarationDeces::where('code_dossier', $code)->first(),
                $agent,
                $user,
                $target,
                'DECES',
                $motif,
            );
        }

        return response()->json(['status' => 'error', 'message' => 'Dossier introuvable.'], 404);
    }

    // ---------------------------------------------------------------
    // Notes de service (ancien + nouveau contrat)
    // ---------------------------------------------------------------
    public function notes(Request $request)
    {
        $query = NoteWorkflowService::visiblesPour($request->user())
            ->with(['structures', 'signataire'])
            ->latest('id');

        $notes = (clone $query)
            ->get()
            ->map(
                fn (NoteService $n) => [
                    'id' => $n->id,
                    'numero' => $n->numero_reference,
                    'libelle' => $n->objet,
                    'date' => $n->date_emission?->format('Y-m-d'),
                    'service' => $n->structures->pluck('nom')->join(', ') ?:
                        'Tous les Services du Ministère',
                    'emetteur' => $n->signataire?->fullName() ?? 'Direction',
                    'signataire_id' => $n->signataire_id,
                    'secretaire' => 'Secrétariat',
                    'statut' => $n->statut,
                    'destinataires_detail' => 'Transmis aux structures : '.
                        ($n->structures->pluck('nom')->join(', ') ?:
                            'Tous les Services du Ministère'),
                ],
            )
            ->all();

        return response()->json([
            'status' => 'success',
            'notes' => $notes,
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Création d'une note de service : l'autorité émettrice la rédige, elle est aussitôt transmise à
     * la secrétaire ; la diffusion est une action séparée.
     */
    public function storeNote(Request $request)
    {
        $action = strtolower((string) $request->input('action', ''));

        // Saisie / mise en forme par le secrétariat (ancien contrat).
        if ($action === 'saisir') {
            abort_unless(
                $request->user()->hasRole('ROLE_SECRETAIRE'),
                403,
                'Saisie réservée au secrétariat.',
            );
            $note = NoteService::find($request->input('note_id'));
            if (! $note) {
                return response()->json(
                    ['status' => 'error', 'message' => 'Note introuvable.'],
                    404,
                );
            }
            $note = NoteWorkflowService::saisir($note, $request->user()->agent, [
                'contenu' => $request->input(
                    'contenu',
                    $note->contenu ?? 'Note mise en forme par le secrétariat.',
                ),
            ]);

            return response()->json([
                'status' => 'success',
                'ref' => $note->numero_reference,
                'statut' => $note->statut,
            ]);
        }

        // Validation par l'autorité émettrice (ancien contrat).
        if ($action === 'valider') {
            $note = NoteService::find($request->input('note_id'));
            if (! $note) {
                return response()->json(
                    ['status' => 'error', 'message' => 'Note introuvable.'],
                    404,
                );
            }
            $note = NoteWorkflowService::valider($note, $request->user()->agent);

            return response()->json([
                'status' => 'success',
                'ref' => $note->numero_reference,
                'statut' => $note->statut,
            ]);
        }

        // Saisie puis transmission aux destinataires par la secrétaire (ancien contrat).
        if ($action === 'diffuse') {
            abort_unless(
                $request->user()->hasRole('ROLE_SECRETAIRE'),
                403,
                'Diffusion réservée au secrétariat.',
            );
            $note = NoteService::find($request->input('note_id'));
            if (
                ! $note ||
                in_array($note->statut, [NoteService::DIFFUSEE, NoteService::ARCHIVEE], true)
            ) {
                return response()->json(
                    ['status' => 'error', 'message' => 'Note introuvable ou déjà diffusée.'],
                    422,
                );
            }
            if ($note->statut === NoteService::EN_ATTENTE_SAISIE) {
                $note = NoteWorkflowService::saisir($note, $request->user()->agent, [
                    'contenu' => $note->contenu ?? 'Note mise en forme par le secrétariat.',
                ]);
            }
            $note = NoteWorkflowService::diffuser($note, $request->user()->agent);

            return response()->json(['status' => 'success', 'ref' => $note->numero_reference]);
        }

        // Nouveau contrat (objet) : rédaction + transmission au secrétariat.
        if ($request->has('objet')) {
            abort_unless(
                $request->user()->hasRole(...NoteWorkflowService::ROLES_EMETTEURS),
                403,
                'Émission réservée à une autorité habilitée.',
            );
            $data = $request->validate([
                'objet' => ['required', 'string', 'min:5'],
                'contenu' => ['nullable', 'string'],
                'structure_ids' => ['nullable', 'array'],
                'structure_ids.*' => ['exists:structures,id'],
            ]);
            $note = NoteWorkflowService::rediger($request->user()->agent, $data, null);
            $note = NoteWorkflowService::transmettreSecretariat($note, $request->user()->agent);

            return response()->json(
                [
                    'status' => 'success',
                    'ref' => $note->numero_reference,
                    'note_id' => $note->id,
                    'statut' => $note->statut,
                    'data' => $note->load('structures'),
                ],
                201,
            );
        }

        // Ancien contrat : émission (titre + structures) puis secrétariat.
        abort_unless(
            $request->user()->hasRole(...NoteWorkflowService::ROLES_EMETTEURS),
            403,
            'Émission réservée à une autorité habilitée.',
        );
        $data = $request->validate([
            'title' => ['required', 'string', 'min:5'],
            'recipient_structure_ids' => ['nullable'],
        ]);
        $note = NoteWorkflowService::rediger(
            $request->user()->agent,
            [
                'objet' => $data['title'],
                'structure_ids' => $data['recipient_structure_ids'] ?? null,
            ],
            null,
        );
        $note = NoteWorkflowService::transmettreSecretariat($note, $request->user()->agent);

        return response()->json(
            [
                'status' => 'success',
                'ref' => $note->numero_reference,
                'note_id' => $note->id,
                'statut' => $note->statut,
            ],
            201,
        );
    }

    /**
     * Renvoie le statut d'une déclaration tel quel : les statuts de l'état civil sont déjà ceux que
     * l'interface attend.
     */
    private function mapEtatCivilStatus(string $statut): string
    {
        return match ($statut) {
            EtatCivilService::EN_ATTENTE_GESTIONNAIRE_RH => 'EN_ATTENTE_GESTIONNAIRE_RH',
            default => $statut,
        };
    }

    // ---------------------------------------------------------------
    // Référentiels & utilisateurs (ancien contrat)
    // ---------------------------------------------------------------
    public function structures()
    {
        $structures = Structure::orderBy('id')->get()->map(
            fn (Structure $s) => [
                'id_structure' => $s->id,
                'code_structure' => $s->code,
                'nom_structure' => $s->nom,
                'type_structure' => $s->type,
            ],
        );

        return response()->json(['status' => 'success', 'structures' => $structures]);
    }

    /** Liste des rôles. */
    public function roles()
    {
        return response()->json(['status' => 'success', 'roles' => Role::orderBy('id')->get()]);
    }

    /**
     * Administrateur : liste des comptes avec rôle réel, structure, statut (actif ou suspendu) et
     * dernière connexion.
     */
    public function users()
    {
        $users = User::with(['roles', 'agent.structure', 'agent.fonction'])
            ->orderBy('id')
            ->get()
            ->map(function (User $u) {
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
                    'structure_id' => $u->agent?->structure_id,
                    'statut' => $u->actif ? 'ACTIF' : 'SUSPENDU',
                    'derniere_connexion' => $u->derniere_connexion?->format('Y-m-d H:i:s'),
                    'role_code' => $code,
                    'role_libelle' => $u->roles->first()?->libelle,
                    'roles' => $u->roles
                        ->map(fn ($r) => ['code_role' => $r->code, 'libelle_role' => $r->libelle])
                        ->all(),
                    'role' => $code
                        ? self::NEW_TO_LEGACY_CODE[$code] ?? 'ROLE_AGENT'
                        : 'ROLE_AGENT',
                ];
            });

        return response()->json(['status' => 'success', 'users' => $users]);
    }

    /**
     * Administrateur : crée un compte (matricule, identité, rôle, structure, mot de passe initial de 6
     * caractères au moins).
     */
    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'matricule' => ['required', 'string', 'max:30'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:150'],
            'role' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6'],
            'structure' => ['nullable', 'string'],
            'structure_id' => ['nullable', 'exists:structures,id'],
            'civilite' => ['nullable', 'string', 'max:10'],
            'telephone' => ['nullable', 'string', 'max:30'],
        ]);

        $roleCodes = $this->resolveRoleCodes($data['role']);
        if ($roleCodes === null) {
            return response()->json(['status' => 'error', 'message' => 'Rôle non reconnu.'], 422);
        }

        $matricule = strtoupper(trim($data['matricule']));
        if (
            Agent::where('matricule', $matricule)->exists() ||
            User::where('matricule', $matricule)->exists()
        ) {
            return response()->json(
                ['status' => 'error', 'message' => 'Ce matricule est déjà utilisé.'],
                422,
            );
        }
        // Contrainte d'unicité (nom, prénom) sur agents : message clair plutôt qu'une erreur SQL.
        if (
            Agent::whereRaw('UPPER(nom) = ? AND UPPER(prenom) = ?', [
                strtoupper(trim($data['nom'])),
                strtoupper(trim($data['prenom'])),
            ])->exists()
        ) {
            return response()->json(
                ['status' => 'error', 'message' => 'Cette personne est déjà enregistrée.'],
                422,
            );
        }

        $structure = ! empty($data['structure_id'])
            ? Structure::find($data['structure_id'])
            : (! empty($data['structure'])
                ? Structure::where('nom', 'like', '%'.trim($data['structure']).'%')->first()
                : null);

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
        JournalAudit::noter(
            JournalAudit::COMPTE,
            'COMPTE_CREE',
            $request->user(),
            "Compte créé pour {$user->matricule} : ".implode(', ', $roleCodes),
            $user->matricule,
        );

        return response()->json(['status' => 'success', 'message' => 'Compte créé.'], 201);
    }

    /** Mot de passe et/ou rôle : un champ absent n'est pas modifié (les rôles existants sont conservés). */
    public function updateUser(Request $request)
    {
        $data = $request->validate([
            'matricule' => ['required', 'string'],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['nullable', 'string'],
            'structure_id' => ['nullable', 'exists:structures,id'],
            'actif' => ['nullable', 'boolean'],
        ]);

        $user = User::where('matricule', strtoupper(trim($data['matricule'])))->first();
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Agent introuvable.'], 404);
        }

        $roleCodes = null;
        if (! empty($data['role'])) {
            $roleCodes = $this->resolveRoleCodes($data['role']);
            if ($roleCodes === null) {
                return response()->json(
                    ['status' => 'error', 'message' => 'Rôle non reconnu.'],
                    422,
                );
            }
        }

        // Garde-fou : l'administrateur ne peut ni se suspendre ni se retirer son propre rôle.
        if (
            $user->id === $request->user()->id &&
            (($data['actif'] ?? true) === false ||
                ($roleCodes !== null && ! in_array('ROLE_ADMIN_DSI', $roleCodes, true)))
        ) {
            return response()->json(
                [
                    'status' => 'error',
                    'message' => 'Vous ne pouvez pas suspendre votre propre compte ni retirer votre rôle d’administrateur.',
                ],
                422,
            );
        }

        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->save();
            $user->tokens()->delete();
        }
        if ($roleCodes !== null) {
            $user->roles()->sync(Role::whereIn('code', $roleCodes)->pluck('id')->all());
        }
        $modifs = array_filter([
            ! empty($data['password']) ? 'mot de passe' : null,
            $roleCodes !== null ? 'rôle → '.implode(', ', $roleCodes) : null,
        ]);
        if (! empty($data['structure_id']) && $user->agent) {
            $user->agent->update(['structure_id' => $data['structure_id']]);
            $user->update(['structure_id' => $data['structure_id']]);
            $modifs[] = 'structure → '.Structure::find($data['structure_id'])?->nom;
        }
        if (
            array_key_exists('actif', $data) &&
            $data['actif'] !== null &&
            (bool) $data['actif'] !== $user->actif
        ) {
            $user->update(['actif' => (bool) $data['actif']]);
            if (! $user->actif) {
                $user->tokens()->delete();
            }
            $modifs[] = $user->actif ? 'compte réactivé' : 'compte suspendu';
        }
        JournalAudit::noter(
            JournalAudit::COMPTE,
            'COMPTE_MODIFIE',
            $request->user(),
            "Compte {$user->matricule} modifié : ".implode(' ; ', $modifs),
            $user->matricule,
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Compte utilisateur mis à jour.',
        ]);
    }

    // ---------------------------------------------------------------
    // Notifications (ancien contrat)
    // ---------------------------------------------------------------
    public function notifications(Request $request)
    {
        $agentId = $request->user()->agent_id;
        $query = Notification::where('agent_id', $agentId)->latest('id');
        $rows = (clone $query)->limit(30)->get()->map(
            fn (Notification $n) => [
                'id_notification' => $n->id,
                'id_agent_destinataire' => $n->agent_id,
                'titre_notif' => $n->titre,
                'message_notif' => $n->message,
                'type_notif' => $n->type,
                'reference_dossier' => $n->reference_dossier,
                'est_lu' => (bool) $n->est_lu,
                'date_creation' => $n->created_at?->format('Y-m-d H:i:s'),
            ],
        );

        return response()->json([
            'status' => 'success',
            'notifications' => $rows,
            'unread_count' => (clone $query)->where('est_lu', false)->count(),
            'data' => Notification::where('agent_id', $agentId)->latest('id')->paginate(30),
            'unread' => (clone $query)->where('est_lu', false)->count(),
        ]);
    }

    /** Marque comme lues les notifications de l'utilisateur connecté (jamais celles d'un autre agent). */
    public function markNotificationsRead(Request $request)
    {
        $agentId = $request->user()->agent_id;
        Notification::where('agent_id', $agentId)->update(['est_lu' => true]);

        return response()->json([
            'status' => 'success',
            'message' => 'Notifications marquées comme lues.',
        ]);
    }

    /**
     * Notification explicite de l'agent par le Gestionnaire RH
     * après décision du DRH (ancien contrat, par code dossier).
     */
    public function notifier(Request $request)
    {
        $data = $request->validate(['id' => ['required', 'string']]);
        abort_unless(
            $request->user()->hasRole('ROLE_GESTIONNAIRE_RH'),
            403,
            'Notification réservée au Gestionnaire RH.',
        );

        $demande = DemandePermission::where('code_dossier', strtoupper(trim($data['id'])))->first();
        if (! $demande) {
            return response()->json(
                ['status' => 'error', 'message' => 'Dossier introuvable.'],
                404,
            );
        }

        PermissionWorkflowService::notifierAgent($demande, $request->user()->agent);

        return response()->json(['status' => 'success']);
    }

    // ---------------------------------------------------------------
    // Helpers privés
    // ---------------------------------------------------------------
    private function primaryProfile(array $codes): string
    {
        foreach (
            [
                'ROLE_ADMIN_DSI' => 'ADMINISTRATEUR',
                'ROLE_DRH' => 'DRH',
                'ROLE_DIRECTEUR' => 'DIRECTEUR',
                'ROLE_SOUS_DIRECTEUR' => 'SOUS_DIRECTEUR',
                'ROLE_GESTIONNAIRE_RH' => 'RESPONSABLE',
                'ROLE_SERVICE_ADMINISTRATIF' => 'SERVICE',
                'ROLE_CHEF_DE_SERVICE' => 'CHEF_SERVICE',
                'ROLE_DIRECTEUR_CABINET' => 'DIRCAB',
                'ROLE_SECRETAIRE' => 'SECRETAIRE',
            ] as $code => $profile
        ) {
            if (in_array($code, $codes, true)) {
                return $profile;
            }
        }

        return 'AGENT';
    }

    /**
     * Construit l'objet utilisateur renvoyé à la connexion : identité, structure, fonction, solde de
     * permission, profil et rôles.
     */
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
            'privileges' => $user->roles->flatMap->privileges
                ->pluck('code')
                ->unique()
                ->values()
                ->all(),
            'unread_notifs' => $agent
                ? Notification::where('agent_id', $agent->id)->where('est_lu', false)->count()
                : 0,
        ];
    }

    /**
     * Traduit le statut d'une demande de permission pour l'interface : les étapes de vérification et
     * de visa deviennent EN_ATTENTE_N1, l'attente du DRH devient EN_ATTENTE_RH ; les autres statuts
     * sont inchangés.
     */
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

    /**
     * Rejette une demande de permission selon son étape actuelle : rejet du gestionnaire RH
     * (vérification), refus de visa du responsable, ou rejet du DRH. Le motif est obligatoire.
     */
    private function rejeterPermission(
        DemandePermission $demande,
        Agent $agent,
        User $user,
        ?string $motif,
    ): void {
        if ($demande->statut === DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH) {
            abort_unless($user->hasRole('ROLE_GESTIONNAIRE_RH'), 403, 'Vérification RH requise.');
            PermissionWorkflowService::verifierRh(
                $demande,
                $agent,
                'rejeter',
                $motif ?? 'Dossier non conforme.',
            );
        } elseif (
            in_array(
                $demande->statut,
                [
                    DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR,
                    DemandePermission::EN_ATTENTE_VISA_DIRECTEUR,
                ],
                true,
            )
        ) {
            $role =
                $demande->statut === DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR
                    ? 'SOUS_DIRECTEUR'
                    : 'DIRECTEUR';
            abort_unless(
                $user->hasRole('ROLE_'.strtoupper($role)),
                403,
                'Visa '.$role.' requis.',
            );
            PermissionWorkflowService::viser(
                $demande,
                $agent,
                $role,
                false,
                $motif ?? 'Avis défavorable hiérarchique.',
            );
        } else {
            abort_unless($user->hasRole('ROLE_DRH'), 403, 'Décision DRH requise.');
            PermissionWorkflowService::trancherDrh(
                $demande,
                $agent,
                false,
                $motif ?? 'Rejet DRH : dossier non conforme.',
            );
        }
    }

    /**
     * Workflow des actes d'état civil : AGENT -> GESTIONNAIRE RH -> DRH -> AGENT
     * Étape 1 : Le Gestionnaire RH vérifie complétude et justificatifs
     *   (EN_ATTENTE_RH = conforme, REJETEE = retour en correction).
     * Étape 2 : Le DRH contrôle et valide (VALIDEE, mise à jour statutaire) ou rejette (REJETEE, avec motif).
     * Toute autre combinaison est refusée : un statut inattendu ne doit jamais rejeter un dossier.
     */
    private function validerActe(
        DeclarationNaissance|DeclarationDeces|null $acte,
        Agent $agent,
        User $user,
        string $target,
        string $type,
        ?string $motif,
    ) {
        if (! $acte) {
            return response()->json(
                ['status' => 'error', 'message' => 'Dossier introuvable.'],
                404,
            );
        }

        if (
            in_array(
                $acte->statut,
                [
                    EtatCivilService::EN_ATTENTE_GESTIONNAIRE_RH,
                    EtatCivilService::EN_ATTENTE_SERVICE,
                ],
                true,
            )
        ) {
            abort_unless(
                $user->hasRole('ROLE_GESTIONNAIRE_RH'),
                403,
                'Vérification du Gestionnaire RH requise.',
            );
            if ($target === 'VALIDEE') {
                return response()->json(
                    [
                        'status' => 'error',
                        'message' => 'La validation finale relève de la DRH : transmettez d’abord le dossier.',
                    ],
                    422,
                );
            }
            if ($target === 'EN_ATTENTE_RH') {
                EtatCivilService::controler($type, $acte, $agent, 'conforme');
            } else {
                EtatCivilService::controler(
                    $type,
                    $acte,
                    $agent,
                    'retourner',
                    $motif ?? 'Dossier incomplet : justificatif ou information manquant.',
                );
            }

            return response()->json(['status' => 'success']);
        }

        if ($acte->statut === EtatCivilService::EN_ATTENTE_RH && $target !== 'EN_ATTENTE_RH') {
            abort_unless($user->hasRole('ROLE_DRH'), 403, 'Décision DRH requise.');
            EtatCivilService::trancher(
                $type,
                $acte,
                $agent,
                $target === 'VALIDEE',
                $target === 'VALIDEE'
                    ? null
                    : $motif ?? 'Déclaration rejetée par la DRH : pièce non conforme.',
            );

            return response()->json(['status' => 'success']);
        }

        return response()->json(
            ['status' => 'error', 'message' => 'Transition impossible à ce stade.'],
            422,
        );
    }

    /**
     * Type de permission d'après le libellé affiché par le frontend
     * (ex. « Repos Médical Court » → « Repos Médical »). Libellé vide : premier type.
     */
    private function resoudreTypePermission(?string $libelle): TypePermission
    {
        if (blank($libelle)) {
            return TypePermission::orderBy('id')->firstOrFail();
        }

        $cible = Str::slug($libelle);
        $type =
            $cible === ''
                ? null
                : TypePermission::all()
                    ->sortByDesc(fn (TypePermission $t) => strlen($t->libelle))
                    ->first(
                        fn (TypePermission $t) => str_contains($cible, Str::slug($t->libelle)) ||
                            str_contains(Str::slug($t->libelle), $cible),
                    );

        abort_if($type === null, 422, "Type de permission inconnu : {$libelle}.");

        return $type;
    }

    /** Justificatif de permission : disque privé + fiche pièce jointe (jamais le disque public). */
    private function joindrePiecePermission(Request $request, DemandePermission $demande): void
    {
        if ($request->hasFile('piece')) {
            $piece = PieceService::deposer(
                $request->file('piece'),
                'permission',
                $demande->id,
                $demande->code_dossier,
                $request->user()->agent,
            );
            $demande->update(['piece_path' => $piece->chemin_stockage]);
        }
    }
}
