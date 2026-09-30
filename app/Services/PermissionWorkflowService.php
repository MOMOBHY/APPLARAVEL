<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\DemandeHistorique;
use App\Models\DemandePermission;
use App\Models\JournalAudit;
use App\Models\Notification;
use App\Models\Structure;
use Carbon\Carbon;
use Illuminate\Support\Str;

/** Permissions : Agent → Gestionnaire RH (retour, rejet ou transmission) → ≤ 2 j :
 * Sous-Directeur/Directeur (conformité) → DRH → Agent notifié ; > 2 j : directement au DRH. */
class PermissionWorkflowService
{
    public const JOURS_MAX = 30;

    /** Rôles qui suivent l'ensemble des demandes (les autres ne voient que les leurs). */
    public const ROLES_SUIVI = [
        'ROLE_GESTIONNAIRE_RH',
        'ROLE_SOUS_DIRECTEUR',
        'ROLE_DIRECTEUR',
        'ROLE_DRH',
        'ROLE_ADMIN_DSI',
    ];

    /** Nombre de jours d'une période, dates de début et de fin comprises (une permission d'un jour a la
     * même date de début et de fin). */
    public static function nombreJours(string $debut, string $fin): int
    {
        $d1 = Carbon::parse($debut)->startOfDay();
        $d2 = Carbon::parse($fin)->startOfDay();

        return max(1, (int) $d1->diffInDays($d2) + 1);
    }

    /** Durée retenue (choix explicite de l'agent, sinon calculée sur les dates) et date de fin
     * correspondante. La limite s'applique dans les deux cas. */
    protected static function periode(array $data): array
    {
        $jours =
            array_key_exists('nombre_jours', $data) && $data['nombre_jours'] !== null
                ? (int) $data['nombre_jours']
                : self::nombreJours($data['date_debut'], $data['date_fin']);

        if ($jours < 1 || $jours > self::JOURS_MAX) {
            abort(422, 'Le nombre de jours doit être compris entre 1 et '.self::JOURS_MAX.'.');
        }

        return [
            $jours,
            Carbon::parse($data['date_debut'])
                ->startOfDay()
                ->addDays($jours - 1)
                ->toDateString(),
        ];
    }

    // AGENT : créer / soumettre / corriger
    public static function soumettre(Agent $agent, array $data, ?DemandePermission $existant = null): DemandePermission
    {
        // Un sous-directeur ou un directeur ne peut pas se déclarer conforme lui-même : sa demande part
        // directement au DRH, sans gestionnaire RH.
        $circuitDrhDirect = $agent->autoriteVisa();
        $roleActeur = $circuitDrhDirect ? 'ROLE_'.strtoupper($agent->roleVisa()) : 'ROLE_AGENT';

        // Pour un brouillon soumis, les dates peuvent être déjà formatées — on les normalise
        if ($existant !== null) {
            $data['date_debut'] = $data['date_debut'] instanceof \Carbon\Carbon
                ? $data['date_debut']->toDateString()
                : (string) ($data['date_debut'] ?? $existant->date_debut?->toDateString());
            $data['date_fin'] = $data['date_fin'] instanceof \Carbon\Carbon
                ? $data['date_fin']->toDateString()
                : (string) ($data['date_fin'] ?? $existant->date_fin?->toDateString());
        }

        [$jours, $data['date_fin']] = self::periode($data);

        $statutInitial = $circuitDrhDirect
            ? DemandePermission::EN_ATTENTE_DRH
            : DemandePermission::EN_ATTENTE_RH;

        if ($existant !== null) {
            $existant->update([
                'type_permission_id' => $data['type_permission_id'] ?? $existant->type_permission_id,
                'date_debut' => $data['date_debut'],
                'date_fin' => $data['date_fin'],
                'nombre_jours' => $jours,
                'motif' => $data['motif'] ?? $existant->motif,
                'piece_path' => $data['piece_path'] ?? $existant->piece_path,
                'statut' => $statutInitial,
            ]);
            $demande = $existant;
        } else {
            $demande = DemandePermission::create([
                'code_dossier' => 'PERM-'.now()->year.'-'.strtoupper(Str::random(6)),
                'agent_id' => $agent->id,
                'type_permission_id' => $data['type_permission_id'],
                'date_debut' => $data['date_debut'],
                'date_fin' => $data['date_fin'],
                'nombre_jours' => $jours,
                'motif' => $data['motif'],
                'piece_path' => $data['piece_path'] ?? null,
                'statut' => $statutInitial,
            ]);
        }

        self::tracer(
            $demande,
            $agent,
            $roleActeur,
            'SOUMISSION',
            null,
            $demande->statut,
            $circuitDrhDirect
                ? "Demande soumise ({$jours} jour(s)) par un responsable : transmission directe au DRH, sans conformité intermédiaire."
                : "Demande soumise ({$jours} jour(s)).",
        );

        if ($circuitDrhDirect) {
            $drh = self::drh();
            if ($drh) {
                self::notifier(
                    $drh->id,
                    'Demande d’un responsable à trancher',
                    "{$agent->fullName()} a déposé la demande {$demande->code_dossier} ({$jours} j) : décision DRH requise.",
                    'ATTENTE_DRH',
                    $demande->code_dossier,
                );
            }

            return $demande;
        }

        $gestionnaire = self::gestionnairePour($agent);
        if ($gestionnaire) {
            self::notifier(
                $gestionnaire->id,
                'Nouvelle demande à vérifier',
                "Demande {$demande->code_dossier} ({$jours} j) soumise par {$agent->fullName()}.",
                'ATTENTE_VERIF',
                $demande->code_dossier,
            );
        }

        return $demande;
    }

    /** Contrôle à faire avant tout dépôt de pièce : demande retournée, corrigée par son auteur. */
    public static function exigerCorrigeable(DemandePermission $demande, ?Agent $agent): void
    {
        if ($demande->statut !== DemandePermission::RETOUR_CORRECTION) {
            abort(422, 'Seule une demande retournée pour correction peut être corrigée.');
        }
        if ($agent === null || $demande->agent_id !== $agent->id) {
            abort(403, 'Seul le demandeur peut corriger sa demande.');
        }
    }

    /** L'agent corrige une demande retournée puis la resoumet au gestionnaire. */
    public static function corrigerEtResoumettre(
        DemandePermission $demande,
        Agent $agent,
        array $data,
    ): DemandePermission {
        self::exigerCorrigeable($demande, $agent);

        [$jours, $data['date_fin']] = self::periode($data);
        $ancien = $demande->statut;
        $demande->update([
            'type_permission_id' => $data['type_permission_id'] ?? $demande->type_permission_id,
            'date_debut' => $data['date_debut'],
            'date_fin' => $data['date_fin'],
            'nombre_jours' => $jours,
            'motif' => $data['motif'] ?? $demande->motif,
            'piece_path' => $data['piece_path'] ?? $demande->piece_path,
            'motif_retour' => null,
            'statut' => DemandePermission::EN_ATTENTE_RH,
        ]);

        self::tracer(
            $demande->refresh(),
            $agent,
            'ROLE_AGENT',
            'CORRECTION_RESOUMISSION',
            $ancien,
            $demande->statut,
            'Demande corrigée et resoumise.',
        );

        $gestionnaire = self::gestionnairePour($agent);
        if ($gestionnaire) {
            self::notifier(
                $gestionnaire->id,
                'Demande corrigée à revérifier',
                "Demande {$demande->code_dossier} corrigée par {$agent->fullName()}.",
                'ATTENTE_VERIF',
                $demande->code_dossier,
            );
        }

        return $demande->refresh();
    }

    // GESTIONNAIRE RH : vérifier (conforme / rejeter / retourner)
    /** Gestionnaire RH : transmettre (routage selon la durée), retourner pour correction ou rejeter ; les
     * anciens libellés « conforme » / « corriger » restent acceptés. */
    public static function verifierRh(
        DemandePermission $demande,
        Agent $gestionnaire,
        string $decision,
        ?string $motif = null,
        ?string $visa = null,
    ): DemandePermission {
        // Normalise les libellés : « transmettre » = conforme, « retourner » = corriger.
        $decision = match ($decision) {
            'transmettre', 'conforme' => 'conforme',
            'retourner', 'corriger' => 'corriger',
            'rejeter' => 'rejeter',
            default => $decision,
        };
        if ($demande->statut !== DemandePermission::EN_ATTENTE_RH) {
            abort(422, 'Vérification impossible à ce stade.');
        }
        if (in_array($decision, ['rejeter', 'corriger'], true) && blank($motif)) {
            abort(422, 'Motif obligatoire pour un rejet ou un retour en correction.');
        }

        $ancien = $demande->statut;
        $demande->gestionnaire_id = $gestionnaire->id;
        $demande->date_verif_rh = now();

        if ($decision === 'rejeter') {
            // Le gestionnaire notifie lui-même l'agent : rien ne reste « à notifier ».
            $demande->statut = DemandePermission::REJETEE;
            $demande->motif_rejet = $motif;
            $demande->notifie_le = now();
            $demande->notifie_par_id = $gestionnaire->id;
            $demande->save();
            self::tracer(
                $demande,
                $gestionnaire,
                'ROLE_GESTIONNAIRE_RH',
                'REJET_RH',
                $ancien,
                $demande->statut,
                $motif,
            );
            self::notifier(
                $demande->agent_id,
                'Demande rejetée par le gestionnaire RH',
                "Votre demande {$demande->code_dossier} est rejetée : {$motif}",
                'REJET',
                $demande->code_dossier,
            );

            return $demande;
        }

        if ($decision === 'corriger') {
            $demande->statut = DemandePermission::RETOUR_CORRECTION;
            $demande->motif_retour = $motif;
            $demande->save();
            self::tracer(
                $demande,
                $gestionnaire,
                'ROLE_GESTIONNAIRE_RH',
                'RETOUR_CORRECTION',
                $ancien,
                $demande->statut,
                $motif,
            );
            self::notifier(
                $demande->agent_id,
                'Demande à corriger',
                "Votre demande {$demande->code_dossier} est retournée pour correction : {$motif}",
                'RETOUR',
                $demande->code_dossier,
            );

            return $demande;
        }

        $demande->avis_gestionnaire = 'CONFORME';

        if ($demande->isCircuitCourt()) {
            // Le niveau vient de la structure de l'agent, jamais d'un choix : sous-direction →
            // sous-directeur, direction ou service rattaché → directeur.
            [$direction, $visa] = self::directionPour($demande->agent);
            $demande->visa_attendu = $visa;
            $demande->statut =
                $visa === 'SOUS_DIRECTEUR'
                    ? DemandePermission::EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR
                    : DemandePermission::EN_ATTENTE_VALIDATION_DIRECTEUR;
            $demande->motif_rejet = null;
            $demande->save();
            self::tracer(
                $demande,
                $gestionnaire,
                'ROLE_GESTIONNAIRE_RH',
                'TRANSMISSION_VISA',
                $ancien,
                $demande->statut,
                "Dossier conforme (≤ 2 j), transmis au {$visa} pour validation.",
            );
            if ($direction) {
                self::notifier(
                    $direction->id,
                    'Validation hiérarchique requise',
                    "Demande {$demande->code_dossier} (≤ 2 j) conforme, votre validation est requise.",
                    'ATTENTE_VALIDATION',
                    $demande->code_dossier,
                );
            }
        } else {
            $demande->statut = DemandePermission::EN_ATTENTE_DRH;
            $demande->motif_rejet = null;
            $demande->save();
            self::tracer(
                $demande,
                $gestionnaire,
                'ROLE_GESTIONNAIRE_RH',
                'TRANSMISSION_DRH',
                $ancien,
                $demande->statut,
                'Dossier conforme (> 2 j), transmis directement au DRH.',
            );
            $drh = self::drh();
            if ($drh) {
                self::notifier(
                    $drh->id,
                    'Demande > 2 jours à trancher',
                    "Demande {$demande->code_dossier} vérifiée conforme, décision DRH requise.",
                    'ATTENTE_DRH',
                    $demande->code_dossier,
                );
            }
        }

        return $demande;
    }

    // SOUS-DIRECTEUR / DIRECTEUR : validation (cas 1 uniquement, ≤ 2 jours)
    public static function viser(
        DemandePermission $demande,
        Agent $directeur,
        string $roleActeur,
        bool $favorable,
        ?string $motif = null,
    ): DemandePermission {
        $attendus = [
            'SOUS_DIRECTEUR' => DemandePermission::EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR,
            'DIRECTEUR' => DemandePermission::EN_ATTENTE_VALIDATION_DIRECTEUR,
        ];
        if (! isset($attendus[$roleActeur]) || $demande->statut !== $attendus[$roleActeur]) {
            abort(422, 'Validation impossible : la demande ne relève pas de ce niveau hiérarchique.');
        }
        if (! $favorable && blank($motif)) {
            abort(422, 'Motif obligatoire en cas de rejet.');
        }
        // Validation « selon la structure » : seul le responsable résolu pour l'agent peut valider.
        [$attendu] = self::directionPour($demande->agent, $roleActeur);
        if ($attendu && $attendu->id !== $directeur->id) {
            abort(
                403,
                "Validation réservée au responsable hiérarchique de la structure de l'agent ({$attendu->fullName()}).",
            );
        }

        $ancien = $demande->statut;
        $demande->visa_direction_id = $directeur->id;
        $demande->date_visa = now();

        if (! $favorable) {
            $demande->statut = DemandePermission::REJETEE;
            $demande->avis_direction = 'DEFAVORABLE';
            $demande->motif_rejet = $motif;
            $demande->save();
            self::tracer(
                $demande,
                $directeur,
                'ROLE_'.strtoupper($roleActeur),
                'REFUS_VISA',
                $ancien,
                $demande->statut,
                $motif,
            );
            self::notifier(
                $demande->agent_id,
                'Demande rejetée par le responsable',
                "Votre demande {$demande->code_dossier} a été REJETÉE. Motif : {$motif}",
                'REJET',
                $demande->code_dossier,
            );

            return $demande;
        }

        $demande->avis_direction = 'FAVORABLE';
        $demande->statut = DemandePermission::EN_ATTENTE_DRH;
        $demande->save();
        self::tracer(
            $demande,
            $directeur,
            'ROLE_'.strtoupper($roleActeur),
            'VISA_FAVORABLE',
            $ancien,
            $demande->statut,
            'Validation accordée, dossier transmis à la DRH.',
        );

        $drh = self::drh();
        if ($drh) {
            self::notifier(
                $drh->id,
                'Dossier validé à trancher',
                "Dossier {$demande->code_dossier} validé hiérarchiquement, décision DRH requise.",
                'ATTENTE_DRH',
                $demande->code_dossier,
            );
        }

        return $demande;
    }

    // DRH : décision finale, notifiée directement à l'agent (aucun relais : le module « décisions à
    // notifier » du gestionnaire RH a été supprimé).
    public static function trancherDrh(
        DemandePermission $demande,
        Agent $drh,
        bool $valide,
        ?string $motif = null,
    ): DemandePermission {
        if ($demande->statut !== DemandePermission::EN_ATTENTE_DRH) {
            abort(422, 'Décision DRH impossible à ce stade.');
        }
        if (! $valide && blank($motif)) {
            abort(422, 'Motif obligatoire en cas de rejet DRH.');
        }

        $ancien = $demande->statut;
        $demande->decideur_drh_id = $drh->id;
        $demande->date_decision = now();
        $demande->decision_drh = $valide ? 'VALIDEE' : 'REJETEE';
        $demande->statut = $valide ? DemandePermission::VALIDEE : DemandePermission::REJETEE;
        $demande->motif_rejet = $valide ? null : $motif;
        $demande->save();

        if ($valide) {
            $agent = $demande->agent;
            $agent->solde_permission_annuel = max(
                0,
                $agent->solde_permission_annuel - $demande->nombre_jours,
            );
            $agent->save();
        }

        self::tracer(
            $demande,
            $drh,
            'ROLE_DRH',
            $valide ? 'VALIDATION_DRH' : 'REJET_DRH',
            $ancien,
            $demande->statut,
            $valide ? 'Demande validée.' : $motif,
        );

        $valideMsg = $valide
            ? "Votre demande {$demande->code_dossier} a été VALIDÉE par la DRH."
            : "Votre demande {$demande->code_dossier} a été REJETÉE par la DRH. Motif : {$motif}";
        self::notifier(
            $demande->agent_id,
            $valide ? 'Demande validée par la DRH' : 'Demande rejetée par la DRH',
            $valideMsg,
            $valide ? 'VALIDATION' : 'REJET',
            $demande->code_dossier,
        );

        return $demande;
    }

    /** DRH : retourne le dossier pour correction avec un motif ; l'agent corrige et resoumet, le circuit
     * reprend à la vérification RH. */
    public static function retournerDrh(
        DemandePermission $demande,
        Agent $drh,
        string $motif,
    ): DemandePermission {
        if ($demande->statut !== DemandePermission::EN_ATTENTE_DRH) {
            abort(422, 'Retour impossible à ce stade.');
        }
        if (blank($motif)) {
            abort(422, 'Motif obligatoire pour un retour en correction.');
        }

        $ancien = $demande->statut;
        $demande->decideur_drh_id = $drh->id;
        $demande->date_decision = now();
        $demande->motif_retour = $motif;
        $demande->statut = DemandePermission::RETOUR_CORRECTION;
        $demande->save();

        self::tracer(
            $demande,
            $drh,
            'ROLE_DRH',
            'RETOUR_DRH',
            $ancien,
            $demande->statut,
            $motif,
        );
        self::notifier(
            $demande->agent_id,
            'Demande retournée pour correction',
            "Votre demande {$demande->code_dossier} a été retournée par la DRH pour correction. Motif : {$motif}",
            'RETOUR_CORRECTION',
            $demande->code_dossier,
        );

        return $demande;
    }

    // Résolution automatique des acteurs (structures, rôles)
    public static function gestionnairePour(Agent $agent): ?Agent
    {
        $base = Agent::whereHas('user.roles', fn ($q) => $q->where('code', 'ROLE_GESTIONNAIRE_RH'));

        return (clone $base)->where('structure_id', $agent->structure_id)->first() ??
            ((clone $base)->whereHas('structure', fn ($q) => $q->where('code', 'DRH'))->first() ??
                $base->first());
    }

    /** Types de structure dont le responsable signe le visa de niveau « Directeur ». Reprend les types
     * réellement utilisés par l'annuaire officiel du Ministère. */
    private const TYPES_DIRECTION = [
        'Direction',
        'Direction centrale',
        'Direction générale',
    ];

    /** Niveau de conformité selon la structure : Sous-Direction (ou service qui en dépend) →
     * sous-directeur ; sinon directeur. Remontée jusqu'au premier ancêtre concerné. */
    public static function niveauVisaPour(?Structure $structure): string
    {
        $courant = $structure;
        $garde = 0;

        while ($courant && $garde++ < 10) {
            if ($courant->type === 'Sous-Direction') {
                return 'SOUS_DIRECTEUR';
            }
            if (in_array($courant->type, self::TYPES_DIRECTION, true)) {
                return 'DIRECTEUR';
            }
            $courant = $courant->parent;
        }

        return 'DIRECTEUR';
    }

    /** Signataire de la conformité pour l'agent : niveau choisi, sinon déduit de sa structure
     * (Sous-Direction ou service rattaché → sous-directeur). */
    public static function directionPour(Agent $agent, ?string $niveau = null): array
    {
        $visa = $niveau ?? self::niveauVisaPour($agent->structure);
        $code = 'ROLE_'.$visa;

        // Responsable désigné de la structure en priorité, sinon même
        // structure, sinon n'importe quel titulaire du rôle.
        $responsable = $agent->structure?->responsable;
        if ($responsable && $responsable->user?->hasRole($code)) {
            return [$responsable, $visa];
        }

        $candidats = Agent::whereHas('user.roles', fn ($q) => $q->where('code', $code));
        $choisi =
            (clone $candidats)->where('structure_id', $agent->structure_id)->first() ??
            $candidats->first();

        return [$choisi, $visa];
    }

    /** Retrouve l'agent titulaire du rôle DRH, en préférant celui de la structure « DRH » s'il y en a
     * plusieurs. */
    public static function drh(): ?Agent
    {
        $base = Agent::whereHas('user.roles', fn ($q) => $q->where('code', 'ROLE_DRH'));

        return (clone $base)->whereHas('structure', fn ($q) => $q->where('code', 'DRH'))->first() ??
            $base->first();
    }

    // Traçabilité & notifications
    public static function tracer(
        DemandePermission $demande,
        ?Agent $acteur,
        ?string $role,
        string $action,
        ?string $ancien,
        ?string $nouveau,
        ?string $commentaire = null,
    ): void {
        DemandeHistorique::create([
            'demande_id' => $demande->id,
            'acteur_agent_id' => $acteur?->id,
            'acteur_nom' => $acteur?->fullName(),
            'acteur_role' => $role,
            'action' => $action,
            'ancien_statut' => $ancien,
            'nouveau_statut' => $nouveau,
            'commentaire' => $commentaire,
        ]);
        JournalAudit::noter(
            JournalAudit::DOSSIER,
            $action,
            $acteur?->user,
            trim(
                ($role ? "[{$role}] " : '').
                    ($ancien || $nouveau ? "{$ancien} → {$nouveau}" : '').
                    ($commentaire ? " — {$commentaire}" : ''),
            ),
            $demande->code_dossier,
        );
    }

    /** Crée une notification pour un agent. */
    protected static function notifier(
        int $agentId,
        string $titre,
        string $message,
        string $type,
        ?string $ref,
    ): void {
        Notification::create([
            'agent_id' => $agentId,
            'titre' => $titre,
            'message' => $message,
            'type' => $type,
            'reference_dossier' => $ref,
        ]);
    }
}
