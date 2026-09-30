<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\DeclarationDeces;
use App\Models\DeclarationHistorique;
use App\Models\DeclarationNaissance;
use App\Models\DemandeHistorique;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Compatibilité avec le frontend historique (public/gfp) : ancien contrat /api/* adossé aux nouveaux
 * modèles et au workflow Cas 1 (≤ 2 j) / Cas 2 (> 2 j). */
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
        'ADMINISTRATEUR' => ['ROLE_ADMIN_DSI'],
    ];

    /** Rôle simple (admin) -> nouveaux codes. RESPONSABLE = Gestionnaire RH seul (séparation vérification / visa). */
    private const SIMPLE_ROLES = [
        'AGENT' => ['ROLE_AGENT'],
        'RESPONSABLE' => ['ROLE_GESTIONNAIRE_RH'],
        'DRH' => ['ROLE_DRH'],
        'ADMINISTRATEUR' => ['ROLE_ADMIN_DSI'],
    ];

    /** Traduit le rôle reçu (profil simple, ancien code ou nouveau code) en codes de rôles réels. Renvoie
     * null si le rôle n'existe pas. */
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
        'ROLE_SERVICE_ADMINISTRATIF' => 'ROLE_SERVICE_ADMINISTRATIF',
    ];

    // Authentification (ancien contrat)
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

    /** Inscription publique : compte créé sans rôle, attribué ensuite par l'administrateur ; le champ «
     * role » est accepté mais ignoré. */
    public function register(Request $request)
    {
        $data = $request->validate([
            'civilite' => ['required', 'string', 'max:10'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:150'],
            'matricule' => ['required', 'string', 'max:30'],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
                // Adresse du ministère ou Gmail : c'est sur elle que
                // l'agent reçoit son code en cas de mot de passe oublié.
                function ($attribut, $valeur, $echec) {
                    $domaine = strtolower(trim($valeur));
                    if (! str_ends_with($domaine, '@fonctionpublique.gouv.ci') && ! str_ends_with($domaine, '@gmail.com')) {
                        $echec("L'adresse email doit finir par @fonctionpublique.gouv.ci ou @gmail.com.");
                    }
                },
            ],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['nullable', 'string'],   // Ignoré — rôle attribué par l'admin
            'structure_id' => ['nullable', 'exists:structures,id'],
        ]);

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
            'email' => strtolower(trim($data['email'])),
            'matricule' => $matricule,
            'password' => $data['password'],
            'agent_id' => $agent->id,
            'structure_id' => $agent->structure_id,
        ]);
        // Aucun rôle n'est attribué à l'inscription — l'admin le fait ensuite.
        JournalAudit::noter(
            JournalAudit::COMPTE,
            'INSCRIPTION',
            $user,
            'Auto-inscription sans rôle (en attente d\'attribution par l\'administrateur).',
        );

        return response()->json(
            [
                'status' => 'success',
                'message' => 'Compte créé avec succès. Un administrateur vous attribuera votre rôle avant votre première connexion.',
                'user' => [
                    'matricule' => $matricule,
                    'nom' => $agent->nom,
                    'prenom' => $agent->prenom,
                    'civilite' => $agent->civilite,
                ],
            ],
            201,
        );
    }

    // Demandes & actes (ancien contrat)
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

        // Dossiers traités par l'utilisateur connecté : une décision du gestionnaire RH ou du directeur
        // reste visible dans son tableau de bord après transmission.
        $moi = $user->agent_id;
        $traiteesPerms = $moi
            ? DemandeHistorique::whereIn('demande_id', $perms->pluck('id'))
                ->where('acteur_agent_id', $moi)
                // Gestionnaire RH (vérification) et directeur / sous-directeur
                // (conformité hiérarchique) : chacun garde les dossiers qu'il a traités.
                ->whereIn('action', ['TRANSMISSION_VISA', 'TRANSMISSION_DRH', 'RETOUR_CORRECTION', 'REJET_RH', 'VISA_FAVORABLE', 'REFUS_VISA'])
                ->pluck('demande_id')
                ->all()
            : [];
        $traiteesNaiss = $moi
            ? DeclarationHistorique::where('type_dossier', 'NAISSANCE')
                ->whereIn('dossier_id', $naissances->pluck('id'))
                ->where('acteur_agent_id', $moi)
                ->whereIn('action', ['VERIFICATION_CONFORME', 'RETOUR_CORRECTION'])
                ->pluck('dossier_id')
                ->all()
            : [];
        $traiteesDeces = $moi
            ? DeclarationHistorique::where('type_dossier', 'DECES')
                ->whereIn('dossier_id', $deces->pluck('id'))
                ->where('acteur_agent_id', $moi)
                ->whereIn('action', ['VERIFICATION_CONFORME', 'RETOUR_CORRECTION'])
                ->pluck('dossier_id')
                ->all()
            : [];

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
                'traite_par_moi' => in_array($p->id, $traiteesPerms, true),
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
                'traite_par_moi' => in_array($n->id, $traiteesNaiss, true),
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
                'traite_par_moi' => in_array($d->id, $traiteesDeces, true),
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

    /** Garde-fou « structure non affectée » : sans rattachement, un agent ne dépose rien (pas de
     * signataire). Fait autorité sur l'interface ; ne vise que les comptes agents. */
    private function exigerStructureAffectee(Request $request): void
    {
        $user = $request->user();
        if (! $user->hasRole('ROLE_AGENT')) {
            return;
        }

        abort_if(
            $user->agent?->structure_id === null,
            403,
            "Votre structure de rattachement n'est pas encore affectée. Contactez l'administrateur : sans structure, impossible de déposer une demande.",
        );
    }

    /** Dépôt d'une demande de permission depuis l'interface : justificatif et lieu obligatoires, durée de
     * 1 à 30 jours. */
    public function submitPermission(Request $request)
    {
        $this->exigerStructureAffectee($request);
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
                'statut' => $demande->statut,
                // Où le dossier atterrit : un responsable va droit au DRH, un agent
                // passe par le gestionnaire RH puis, sur le cas court, par un visa.
                'circuit' => $demande->statut === DemandePermission::EN_ATTENTE_DRH
                    ? 'DRH'
                    : ($demande->nombre_jours <= 2 ? 'DIRECTION' : 'DRH'),
                'niveau_requis' => $demande->nombre_jours <= 2 ? 'DIRECTION' : 'DRH',
            ],
            201,
        );
    }

    /** Enregistre une demande de permission en BROUILLON sans la soumettre au circuit. */
    public function sauvegarderBrouillonPermission(Request $request)
    {
        $this->exigerStructureAffectee($request);
        $reglePiece = [
            'nullable', 'file',
            'mimes:'.implode(',', PieceService::MIMES),
            'max:'.PieceService::MAX_KO,
        ];
        $data = $request->validate([
            'type_permission_id' => ['nullable', 'exists:types_permission,id'],
            'typePerm' => ['nullable', 'string'],
            'date_debut' => ['nullable', 'date'],
            'dateDebut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date'],
            'dateFin' => ['nullable', 'date'],
            'motif' => ['nullable', 'string', 'min:3'],
            'jours' => ['nullable', 'integer', 'min:1'],
            'piece' => $request->hasFile('piece') ? $reglePiece : ['nullable'],
            'code_dossier' => ['nullable', 'string'],  // si mise à jour d'un brouillon existant
        ]);

        $agent = $request->user()->agent;
        $debut = $data['date_debut'] ?? $data['dateDebut'] ?? null;
        $fin = $data['date_fin'] ?? $data['dateFin'] ?? null;
        $typeId = $data['type_permission_id'] ?? $this->resoudreTypePermission($data['typePerm'] ?? null)->id;

        // Mise à jour d'un brouillon existant
        if (!empty($data['code_dossier'])) {
            $demande = DemandePermission::where('code_dossier', $data['code_dossier'])
                ->where('agent_id', $agent->id)
                ->where('statut', 'BROUILLON')
                ->first();
            if (!$demande) {
                return response()->json(['status' => 'error', 'message' => 'Brouillon introuvable.'], 404);
            }
            $demande->update(array_filter([
                'type_permission_id' => $typeId,
                'date_debut' => $debut,
                'date_fin' => $fin,
                'nombre_jours' => $data['jours'] ?? ($debut && $fin ? max(1, (int)round((strtotime($fin) - strtotime($debut)) / 86400) + 1) : null),
                'motif' => $data['motif'] ?? null,
            ], fn($v) => $v !== null));
        } else {
            // Création d'un nouveau brouillon
            $demande = DemandePermission::create([
                'code_dossier' => 'PERM-'.now()->year.'-'.strtoupper(\Illuminate\Support\Str::random(6)),
                'agent_id' => $agent->id,
                'type_permission_id' => $typeId,
                'date_debut' => $debut,
                'date_fin' => $fin,
                'nombre_jours' => $data['jours'] ?? ($debut && $fin ? max(1, (int)round((strtotime($fin) - strtotime($debut)) / 86400) + 1) : 1),
                'motif' => $data['motif'] ?? '',
                'statut' => 'BROUILLON',
            ]);
        }

        if ($request->hasFile('piece')) {
            $this->joindrePiecePermission($request, $demande);
        }

        return response()->json(['status' => 'success', 'code' => $demande->code_dossier, 'dossier_id' => $demande->id], 201);
    }

    /** Soumet un brouillon de permission existant au circuit de traitement. */
    public function soumettrebrouillonPermission(Request $request)
    {
        $this->exigerStructureAffectee($request);
        $data = $request->validate(['code_dossier' => ['required', 'string']]);
        $agent = $request->user()->agent;
        $demande = DemandePermission::where('code_dossier', $data['code_dossier'])
            ->where('agent_id', $agent->id)
            ->where('statut', 'BROUILLON')
            ->first();
        if (!$demande) {
            return response()->json(['status' => 'error', 'message' => 'Brouillon introuvable ou déjà soumis.'], 404);
        }
        // Un brouillon peut être enregistré incomplet : on exige ici ce que
        // submitPermission() exige pour une demande déposée directement.
        if (mb_strlen(trim((string) $demande->motif)) < 3 || ! $demande->date_debut || ! $demande->date_fin) {
            return response()->json(['status' => 'error', 'message' => 'Complétez le motif et les dates avant de soumettre.'], 422);
        }
        if ($demande->date_fin->lt($demande->date_debut)) {
            return response()->json(['status' => 'error', 'message' => 'La date de fin doit être postérieure ou égale à la date de début.'], 422);
        }
        PermissionWorkflowService::soumettre($agent, [
            'type_permission_id' => $demande->type_permission_id,
            'date_debut' => $demande->date_debut,
            'date_fin' => $demande->date_fin,
            'motif' => $demande->motif,
            'nombre_jours' => $demande->nombre_jours,
            'piece_path' => $demande->piece_path,
        ], $demande);

        return response()->json([
            'status' => 'success',
            'code' => $demande->refresh()->code_dossier,
            'statut' => $demande->statut,
        ]);
    }

    /** Enregistre une déclaration de naissance ou de décès en BROUILLON. */
    public function sauvegarderBrouillonDeclaration(Request $request)
    {
        $this->exigerStructureAffectee($request);
        $reglePiece = ['nullable', 'file', 'mimes:'.implode(',', PieceService::MIMES), 'max:'.PieceService::MAX_KO];
        $data = $request->validate([
            'nature' => ['required', 'in:NAISSANCE,DECES'],
            'nom' => ['nullable', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:150'],
            'date' => ['nullable', 'date'],
            'lieu' => ['nullable', 'string', 'max:255'],
            'lien_parente' => ['nullable', 'in:ascendant,descendant,conjoint'],
            'extrait' => $request->hasFile('extrait') ? $reglePiece : ['nullable'],
            'certificat' => $request->hasFile('certificat') ? $reglePiece : ['nullable'],
            'code_dossier' => ['nullable', 'string'],
        ]);

        $agent = $request->user()->agent;
        $type = $data['nature'];

        if (!empty($data['code_dossier'])) {
            $modele = $type === 'NAISSANCE' ? DeclarationNaissance::class : DeclarationDeces::class;
            $decl = $modele::where('code_dossier', $data['code_dossier'])
                ->where('agent_id', $agent->id)
                ->where('statut', EtatCivilService::BROUILLON)
                ->first();
            if (!$decl) {
                return response()->json(['status' => 'error', 'message' => 'Brouillon introuvable.'], 404);
            }
            $maj = array_filter([
                'nom_enfant' => $type === 'NAISSANCE' ? ($data['nom'] ?? null) : null,
                'prenom_enfant' => $type === 'NAISSANCE' ? ($data['prenom'] ?? null) : null,
                'date_naissance_enfant' => $type === 'NAISSANCE' ? ($data['date'] ?? null) : null,
                'lieu_naissance_enfant' => $type === 'NAISSANCE' ? ($data['lieu'] ?? null) : null,
                'nom_defunt' => $type === 'DECES' ? ($data['nom'] ?? null) : null,
                'prenom_defunt' => $type === 'DECES' ? ($data['prenom'] ?? null) : null,
                'date_deces' => $type === 'DECES' ? ($data['date'] ?? null) : null,
                'lieu_deces' => $type === 'DECES' ? ($data['lieu'] ?? null) : null,
                'lien_parente' => $type === 'DECES' ? ($data['lien_parente'] ?? null) : null,
            ], fn($v) => $v !== null);

            // Justificatif joint en modifiant le brouillon : sans lui, la soumission
            // qui suit serait refusée faute de pièce alors que l'agent l'a fournie.
            $champFichier = $type === 'NAISSANCE' ? 'extrait' : 'certificat';
            if ($request->hasFile($champFichier)) {
                $piece = PieceService::deposer(
                    $request->file($champFichier),
                    strtolower($type),
                    $decl->id,
                    $decl->code_dossier,
                    $agent,
                );
                $maj[$type === 'NAISSANCE' ? 'extrait_path' : 'certificat_path'] = $piece->chemin_stockage;
            }

            $decl->update($maj);
            $declaration = $decl;
        } else {
            $piece = null;
            if ($type === 'NAISSANCE' && $request->hasFile('extrait')) {
                $piece = PieceService::deposer($request->file('extrait'), 'naissance', null, null, $agent);
            } elseif ($type === 'DECES' && $request->hasFile('certificat')) {
                $piece = PieceService::deposer($request->file('certificat'), 'deces', null, null, $agent);
            }
            $declaration = EtatCivilService::declarer($type, $agent,
                $type === 'NAISSANCE'
                    ? ['nom_enfant' => $data['nom'] ?? '', 'prenom_enfant' => $data['prenom'] ?? '', 'date_naissance_enfant' => $data['date'] ?? now()->format('Y-m-d'), 'lieu_naissance_enfant' => $data['lieu'] ?? '', 'soumettre' => false]
                    : ['nom_defunt' => $data['nom'] ?? '', 'prenom_defunt' => $data['prenom'] ?? '', 'lien_parente' => $data['lien_parente'] ?? 'ascendant', 'date_deces' => $data['date'] ?? now()->format('Y-m-d'), 'lieu_deces' => $data['lieu'] ?? '', 'soumettre' => false],
                $piece?->chemin_stockage
            );
            // Le dossier n'existait pas au moment du dépôt : on rattache la pièce,
            // sinon elle resterait orpheline et ne serait pas supprimée avec le brouillon.
            $piece?->update([
                'dossier_id' => $declaration->id,
                'reference_dossier' => $declaration->code_dossier,
            ]);
        }

        return response()->json(['status' => 'success', 'code' => $declaration->code_dossier, 'dossier_id' => $declaration->id], 201);
    }

    /** Soumet un brouillon de déclaration d'état civil au circuit de traitement. */
    public function soumettrebrouillonDeclaration(Request $request)
    {
        $this->exigerStructureAffectee($request);
        $data = $request->validate([
            'nature' => ['required', 'in:NAISSANCE,DECES'],
            'code_dossier' => ['required', 'string'],
        ]);
        $agent = $request->user()->agent;
        $type = $data['nature'];
        $modele = $type === 'NAISSANCE' ? DeclarationNaissance::class : DeclarationDeces::class;
        $decl = $modele::where('code_dossier', $data['code_dossier'])
            ->where('agent_id', $agent->id)
            ->where('statut', EtatCivilService::BROUILLON)
            ->first();
        if (!$decl) {
            return response()->json(['status' => 'error', 'message' => 'Brouillon introuvable ou déjà soumis.'], 404);
        }
        // Mêmes exigences que submitDeclaration() : le brouillon a pu être enregistré incomplet.
        [$nom, $prenom, $date, $lieu] = $type === 'NAISSANCE'
            ? [$decl->nom_enfant, $decl->prenom_enfant, $decl->date_naissance_enfant, $decl->lieu_naissance_enfant]
            : [$decl->nom_defunt, $decl->prenom_defunt, $decl->date_deces, $decl->lieu_deces];
        if (blank($nom) || blank($prenom) || blank($lieu) || ! $date) {
            return response()->json(['status' => 'error', 'message' => 'Complétez le nom, le prénom, la date et le lieu avant de soumettre.'], 422);
        }
        if ($date->isFuture()) {
            return response()->json(['status' => 'error', 'message' => 'La date ne peut pas être postérieure à aujourd\'hui.'], 422);
        }
        EtatCivilService::soumettreBrouillon($type, $decl, $agent);

        return response()->json([
            'status' => 'success',
            'code' => $decl->refresh()->code_dossier,
            'statut' => $decl->statut,
        ]);
    }

    /** Supprime définitivement un brouillon de l'agent connecté, avec ses fichiers et son historique. Un
     * dossier déjà soumis est refusé : il fait partie d'une décision tracée. */
    public function supprimerBrouillon(Request $request)
    {
        $data = $request->validate(['code_dossier' => ['required', 'string']]);
        $agent = $request->user()->agent;
        $code = strtoupper(trim($data['code_dossier']));

        // Le dossier est cherché dans les trois natures, comme le fait updateStatus().
        $trouve = DemandePermission::where('code_dossier', $code)
            ->where('agent_id', $agent->id)
            ->where('statut', 'BROUILLON')
            ->first();
        $nature = 'PERMISSION';
        if (! $trouve) {
            foreach (['NAISSANCE' => DeclarationNaissance::class, 'DECES' => DeclarationDeces::class] as $type => $modele) {
                $trouve = $modele::where('code_dossier', $code)
                    ->where('agent_id', $agent->id)
                    ->where('statut', EtatCivilService::BROUILLON)
                    ->first();
                if ($trouve) {
                    $nature = $type;
                    break;
                }
            }
        }

        if (! $trouve) {
            // Le code existe peut-être mais chez un autre agent ou déjà sorti du brouillon.
            $existe = DemandePermission::where('code_dossier', $code)->exists()
                || DeclarationNaissance::where('code_dossier', $code)->exists()
                || DeclarationDeces::where('code_dossier', $code)->exists();

            return response()->json([
                'status' => 'error',
                'message' => $existe
                    ? 'Ce dossier n\'est pas un brouillon : seuls vos brouillons non soumis peuvent être supprimés.'
                    : 'Brouillon introuvable.',
            ], $existe ? 403 : 404);
        }

        $this->purgerBrouillon($trouve, $nature);

        JournalAudit::noter(
            JournalAudit::DOSSIER,
            'suppression_brouillon',
            $request->user(),
            'Brouillon '.$nature.' supprimé par son auteur.',
            $code,
        );

        return response()->json(['status' => 'success', 'code' => $code]);
    }

    /** Nettoie ce qui dépend d'un brouillon (historique, pièces jointes, fichiers), faute de clé
     * étrangère, avant de le supprimer. */
    private function purgerBrouillon($dossier, string $nature): void
    {
        $typeDossier = strtolower($nature);

        $pieces = PieceJointe::where('dossier_type', $typeDossier)
            ->where(function ($q) use ($dossier) {
                $q->where('dossier_id', $dossier->id)
                    // Rattrape les pièces déposées avant que le lien soit fait.
                    ->orWhere('reference_dossier', $dossier->code_dossier);
            })
            ->get();
        foreach ($pieces as $piece) {
            Storage::disk('local')->delete($piece->chemin_stockage);
            $piece->delete();
        }

        // Colonne du fichier portée par le dossier lui-même (les créations antérieures
        // à pieces_jointes y écrivent directement le chemin).
        $colonne = match ($nature) {
            'NAISSANCE' => 'extrait_path',
            'DECES' => 'certificat_path',
            default => 'piece_path',
        };
        $chemin = $dossier->getAttribute($colonne);
        if (is_string($chemin) && $chemin !== '') {
            Storage::disk('local')->delete($chemin);
        }

        // demande_historique part en cascade via sa clé étrangère ; celui des
        // déclarations d'état civil n'en a pas et doit être effacé à la main.
        if ($nature !== 'PERMISSION') {
            DeclarationHistorique::where('type_dossier', $nature)
                ->where('dossier_id', $dossier->id)
                ->delete();
        }

        $dossier->delete();
    }

    /** Mêmes exigences que /api/naissances et /api/deces : lieu réel, lien de parenté restreint et pièce
     * justificative scannée obligatoire. */
    public function submitDeclaration(Request $request)
    {
        $this->exigerStructureAffectee($request);
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
            [
                'status' => 'success',
                'code' => $declaration->code_dossier,
                'statut' => $declaration->statut,
                'data' => $declaration,
            ],
            201,
        );
    }

    /** Ancien contrat POST /api/status branché sur le workflow : EN_ATTENTE_RH = étape suivante, VALIDEE
     * = décision DRH, REJETEE = refus ou retour avec motif. */
    public function updateStatus(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'string'],
            'statut' => ['required', 'in:EN_ATTENTE_RH,VALIDEE,REJETEE,RETOUR_CORRECTION'],
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
                if ($demande->statut === DemandePermission::EN_ATTENTE_RH) {
                    if (! $user->hasRole('ROLE_GESTIONNAIRE_RH')) {
                        return response()->json(
                            ['status' => 'error', 'message' => 'Vérification RH requise.'],
                            403,
                        );
                    }
                    PermissionWorkflowService::verifierRh($demande, $agent, 'transmettre');
                } elseif (
                    in_array(
                        $demande->statut,
                        [
                            DemandePermission::EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR,
                            DemandePermission::EN_ATTENTE_VALIDATION_DIRECTEUR,
                        ],
                        true,
                    )
                ) {
                    $role =
                        $demande->statut === DemandePermission::EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR
                            ? 'SOUS_DIRECTEUR'
                            : 'DIRECTEUR';
                    if (! $user->hasRole('ROLE_'.strtoupper($role))) {
                        return response()->json(
                            ['status' => 'error', 'message' => 'Validation '.$role.' requise.'],
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
            } elseif ($target === 'RETOUR_CORRECTION') {
                if (! $user->hasRole('ROLE_DRH')) {
                    return response()->json(
                        ['status' => 'error', 'message' => 'Décision DRH requise.'],
                        403,
                    );
                }
                PermissionWorkflowService::retournerDrh($demande, $agent, $motif ?? '');
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

    // Notes de service (ancien + nouveau contrat)
    public function notes(Request $request)
    {
        // L'historique des étapes ne sert qu'à la secrétaire (onglet « Historique ») :
        // les autres profils n'en ont pas besoin et ne le chargent pas.
        $avecHistorique = $request->user()->hasRole('ROLE_SECRETAIRE');

        $query = NoteWorkflowService::visiblesPour($request->user())
            ->with(['structures', 'signataire'])
            ->latest('id');

        $notes = (clone $query)
            ->when($avecHistorique, fn ($q) => $q->with('historique'))
            ->get()
            ->map(
                fn (NoteService $n) => ($avecHistorique ? [
                    // Du plus ancien au plus récent : l'ordre de lecture d'une frise.
                    'historique' => $n->historique
                        ->sortBy('id')
                        ->values()
                        ->map(fn ($h) => [
                            'action' => $h->action,
                            'acteur' => $h->acteur_nom,
                            'role' => $h->acteur_role,
                            'ancien_statut' => $h->ancien_statut,
                            'nouveau_statut' => $h->nouveau_statut,
                            'commentaire' => $h->commentaire,
                            'date' => $h->created_at?->toIso8601String(),
                        ])
                        ->all(),
                ] : []) + [
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

    /** Création d'une note de service : l'autorité émettrice la rédige, elle est aussitôt transmise à la
     * secrétaire ; la diffusion est une action séparée. */
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

        // Envoi aux directeurs par le secrétariat (brouillon ou note reprise).
        if ($action === 'envoyer') {
            abort_unless(
                $request->user()->hasRole('ROLE_SECRETAIRE'),
                403,
                'Envoi réservé au secrétariat.',
            );
            $note = NoteService::find($request->input('note_id'));
            if (! $note) {
                return response()->json(
                    ['status' => 'error', 'message' => 'Note introuvable.'],
                    404,
                );
            }
            $note = NoteWorkflowService::envoyerAuxDirecteurs($note, $request->user()->agent);

            return response()->json([
                'status' => 'success',
                'ref' => $note->numero_reference,
                'statut' => $note->statut,
            ]);
        }

        // Diffusion aux destinataires par la secrétaire, une fois la note validée
        // par un directeur (ancien contrat).
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
            /* Pas de saisie automatique : le directeur doit d'abord valider, sans quoi la diffusion est
               refusée. La saisie et la diffusion sont deux étapes distinctes de la secrétaire. */
            $note = NoteWorkflowService::diffuser($note, $request->user()->agent);

            return response()->json([
                'status' => 'success',
                'ref' => $note->numero_reference,
                // Bilan de l'email envoyé à tous les agents du ministère.
                'emails' => NoteWorkflowService::$dernierBilanEmails,
            ]);
        }

        // Nouveau contrat (objet) : rédaction par le secrétariat (brouillon).
        // L'envoi aux directeurs est une action séparée.
        if ($request->has('objet')) {
            abort_unless(
                $request->user()->hasRole(...NoteWorkflowService::ROLES_EMETTEURS),
                403,
                'Rédaction réservée au secrétariat.',
            );
            $data = $request->validate([
                'objet' => ['required', 'string', 'min:5'],
                'contenu' => ['nullable', 'string'],
                'structure_ids' => ['nullable', 'array'],
                'structure_ids.*' => ['exists:structures,id'],
            ]);
            $note = NoteWorkflowService::rediger($request->user()->agent, $data, null);

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

        // Ancien contrat : rédaction par le secrétariat (titre + structures).
        abort_unless(
            $request->user()->hasRole(...NoteWorkflowService::ROLES_EMETTEURS),
            403,
            'Rédaction réservée au secrétariat.',
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

    /** Renvoie le statut d'une déclaration tel quel : les statuts de l'état civil sont déjà ceux que
     * l'interface attend. */
    private function mapEtatCivilStatus(string $statut): string
    {
        return match ($statut) {
            EtatCivilService::EN_ATTENTE_GESTIONNAIRE_RH => 'EN_ATTENTE_GESTIONNAIRE_RH',
            default => $statut,
        };
    }

    // Référentiels & utilisateurs (ancien contrat)
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

    /** Administrateur : liste des comptes avec rôle réel, structure, statut (actif ou suspendu) et
     * dernière connexion. */
    public function users()
    {
        // Structures dirigées par chaque agent (responsable de structure, qui donne le visa).
        $dirigees = Structure::whereNotNull('responsable_agent_id')
            ->orderBy('nom')
            ->get()
            ->groupBy('responsable_agent_id');

        $users = User::with(['roles', 'agent.structure', 'agent.fonction'])
            ->orderBy('id')
            ->get()
            ->map(function (User $u) use ($dirigees) {
                $code = $u->roles->first()?->code;

                return [
                    'id_agent' => $u->agent?->id,
                    'responsable_de' => ($dirigees[$u->agent_id] ?? collect())->pluck('nom')->values(),
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

    /** Administrateur : crée un compte (matricule, identité, rôle, structure, mot de passe initial de 6
     * caractères au moins). */
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
            // L'administrateur n'est pas tenu de saisir l'email : à défaut,
            // une adresse générique est générée (l'agent la fera corriger).
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
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
            'email' => isset($data['email'])
                ? strtolower(trim($data['email']))
                : strtolower($matricule).'@fonctionpublique.gouv.ci',
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
            // La règle « boolean » accepte aussi "0" et 0 : on compare la valeur convertie.
            ((isset($data['actif']) && ! filter_var($data['actif'], FILTER_VALIDATE_BOOLEAN)) ||
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
            $nouvelle = Structure::find($data['structure_id']);
            // Notification seulement si le rattachement change vraiment (pas à chaque enregistrement) ;
            // comparaison sur l'agent, source du garde-fou de dépôt.
            $change = (string) $user->agent->structure_id !== (string) $data['structure_id'];
            $user->agent->update(['structure_id' => $data['structure_id']]);
            $user->update(['structure_id' => $data['structure_id']]);
            $modifs[] = 'structure → '.$nouvelle?->nom;

            if ($change) {
                $libelleStructure = $nouvelle?->nom
                    ? "{$nouvelle->nom} ({$nouvelle->type})"
                    : 'votre structure';
                // Le signataire annoncé suit la structure : sous-direction → sous-directeur.
                $signataire = $nouvelle?->type === 'Sous-Direction' ? 'Sous-Directeur' : 'Directeur';
                Notification::create([
                    'agent_id' => $user->agent->id,
                    'titre' => 'Structure de rattachement affectée',
                    'message' => "Vous avez été rattaché à la {$libelleStructure}. Vous pouvez désormais déposer vos "
                        .'demandes de permission et vos déclarations d\'état civil. Vos dossiers de 2 jours ou '
                        ."moins relèvent du {$signataire}.",
                    'type' => 'STRUCTURE',
                    'reference_dossier' => null,
                ]);
            }
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

    // Notifications (ancien contrat)
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

    // Helpers privés
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

    /** Construit l'objet utilisateur renvoyé à la connexion : identité, structure, fonction, solde de
     * permission, profil et rôles. */
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
            'structure_id' => $agent?->structure_id,
            'structure_type' => $agent?->structure?->type,
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

    /** Statut d'une permission pour l'interface : vérification et conformité hiérarchique →
     * EN_ATTENTE_N1, attente DRH → EN_ATTENTE_RH ; le reste inchangé. */
    private function mapPermissionStatus(string $statut): string
    {
        return match ($statut) {
            DemandePermission::EN_ATTENTE_RH,
            DemandePermission::EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR,
            DemandePermission::EN_ATTENTE_VALIDATION_DIRECTEUR => 'EN_ATTENTE_N1',
            DemandePermission::EN_ATTENTE_DRH => 'EN_ATTENTE_RH',
            default => $statut,
        };
    }

    /** Rejette une permission selon son étape : gestionnaire RH, responsable hiérarchique ou DRH. Le
     * motif est obligatoire. */
    private function rejeterPermission(
        DemandePermission $demande,
        Agent $agent,
        User $user,
        ?string $motif,
    ): void {
        if ($demande->statut === DemandePermission::EN_ATTENTE_RH) {
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
                    DemandePermission::EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR,
                    DemandePermission::EN_ATTENTE_VALIDATION_DIRECTEUR,
                ],
                true,
            )
        ) {
            $role =
                $demande->statut === DemandePermission::EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR
                    ? 'SOUS_DIRECTEUR'
                    : 'DIRECTEUR';
            abort_unless(
                $user->hasRole('ROLE_'.strtoupper($role)),
                403,
                'Validation '.$role.' requise.',
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

    /** Décision sur une naissance ou un décès : Agent → Gestionnaire RH (conforme ou retour) → DRH
     * (valide, rejette ou retourne) → Agent notifié. */
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

        if ($acte->statut === EtatCivilService::EN_ATTENTE_RH) {
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

        if ($acte->statut === EtatCivilService::EN_ATTENTE_DRH && $target !== 'EN_ATTENTE_RH') {
            abort_unless($user->hasRole('ROLE_DRH'), 403, 'Décision DRH requise.');
            if ($target === 'RETOUR_CORRECTION') {
                EtatCivilService::retourner(
                    $type,
                    $acte,
                    $agent,
                    $motif ?? 'Déclaration retournée par la DRH : pièce ou information à corriger.',
                );

                return response()->json(['status' => 'success']);
            }
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

    /** Type de permission d'après le libellé affiché par le frontend (ex. « Repos Médical Court » → «
     * Repos Médical »). Libellé vide : premier type. */
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
