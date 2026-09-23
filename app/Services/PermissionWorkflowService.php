<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\DemandeHistorique;
use App\Models\DemandePermission;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Workflow des permissions selon la durée.
 *
 * Cas 1 (≤ 2 j) : AGENT → GESTIONNAIRE RH → SOUS-DIRECTEUR/DIRECTEUR (visa)
 *   → DRH → GESTIONNAIRE RH → AGENT.
 * Cas 2 (> 2 j) : AGENT → GESTIONNAIRE RH → DRH → GESTIONNAIRE RH → AGENT.
 *
 * Les acteurs sont résolus depuis les données (structure, rôles), jamais en dur.
 * Chaque transition est historisée et notifiée.
 */
class PermissionWorkflowService
{
    public static function nombreJours(string $debut, string $fin): int
    {
        $d1 = Carbon::parse($debut)->startOfDay();
        $d2 = Carbon::parse($fin)->startOfDay();

        return max(1, $d1->diffInDays($d2) + 1);
    }

    // ---------------------------------------------------------------
    // AGENT : créer / soumettre / corriger
    // ---------------------------------------------------------------
    public static function soumettre(Agent $agent, array $data): DemandePermission
    {
        if (array_key_exists('nombre_jours', $data) && $data['nombre_jours'] !== null) {
            $jours = (int) $data['nombre_jours'];
            if ($jours < 1 || $jours > 30) {
                abort(422, 'Le nombre de jours doit être compris entre 1 et 30.');
            }
            $data['date_fin'] = Carbon::parse($data['date_debut'])->startOfDay()->addDays($jours - 1)->toDateString();
        } else {
            $jours = self::nombreJours($data['date_debut'], $data['date_fin']);
        }

        $demande = DemandePermission::create([
            'code_dossier' => 'PERM-'.now()->year.'-'.strtoupper(Str::random(6)),
            'agent_id' => $agent->id,
            'type_permission_id' => $data['type_permission_id'],
            'date_debut' => $data['date_debut'],
            'date_fin' => $data['date_fin'],
            'nombre_jours' => $jours,
            'motif' => $data['motif'],
            'piece_path' => $data['piece_path'] ?? null,
            'statut' => DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH,
        ]);

        self::tracer($demande, $agent, 'ROLE_AGENT', 'SOUMISSION', null, $demande->statut,
            "Demande soumise ({$jours} jour(s)).");

        $gestionnaire = self::gestionnairePour($agent);
        if ($gestionnaire) {
            self::notifier($gestionnaire->id, 'Nouvelle demande à vérifier',
                "Demande {$demande->code_dossier} ({$jours} j) soumise par {$agent->fullName()}.",
                'ATTENTE_VERIF', $demande->code_dossier);
        }

        return $demande;
    }

    /** L'agent corrige une demande retournée puis la resoumet au gestionnaire. */
    public static function corrigerEtResoumettre(DemandePermission $demande, Agent $agent, array $data): DemandePermission
    {
        if ($demande->statut !== DemandePermission::RETOUR_CORRECTION) {
            abort(422, 'Seule une demande retournée pour correction peut être corrigée.');
        }
        if ($demande->agent_id !== $agent->id) {
            abort(403, 'Seul le demandeur peut corriger sa demande.');
        }

        $jours = self::nombreJours($data['date_debut'], $data['date_fin']);
        if (array_key_exists('nombre_jours', $data) && $data['nombre_jours'] !== null) {
            $jours = (int) $data['nombre_jours'];
            if ($jours < 1 || $jours > 30) {
                abort(422, 'Le nombre de jours doit être compris entre 1 et 30.');
            }
            $data['date_fin'] = Carbon::parse($data['date_debut'])->startOfDay()->addDays($jours - 1)->toDateString();
        }
        $ancien = $demande->statut;
        $demande->update([
            'type_permission_id' => $data['type_permission_id'] ?? $demande->type_permission_id,
            'date_debut' => $data['date_debut'],
            'date_fin' => $data['date_fin'],
            'nombre_jours' => $jours,
            'motif' => $data['motif'] ?? $demande->motif,
            'piece_path' => $data['piece_path'] ?? $demande->piece_path,
            'motif_retour' => null,
            'statut' => DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH,
        ]);

        self::tracer($demande->refresh(), $agent, 'ROLE_AGENT', 'CORRECTION_RESOUMISSION', $ancien, $demande->statut,
            'Demande corrigée et resoumise.');

        $gestionnaire = self::gestionnairePour($agent);
        if ($gestionnaire) {
            self::notifier($gestionnaire->id, 'Demande corrigée à revérifier',
                "Demande {$demande->code_dossier} corrigée par {$agent->fullName()}.",
                'ATTENTE_VERIF', $demande->code_dossier);
        }

        return $demande->refresh();
    }

    // ---------------------------------------------------------------
    // GESTIONNAIRE RH : vérifier (conforme / rejeter / retourner)
    // ---------------------------------------------------------------
    /**
     * @param  'conforme'|'rejeter'|'corriger'  $decision
     */
    public static function verifierRh(DemandePermission $demande, Agent $gestionnaire, string $decision, ?string $motif = null): DemandePermission
    {
        if ($demande->statut !== DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH) {
            abort(422, 'Vérification impossible à ce stade.');
        }
        if (in_array($decision, ['rejeter', 'corriger'], true) && blank($motif)) {
            abort(422, 'Motif obligatoire pour un rejet ou un retour en correction.');
        }

        $ancien = $demande->statut;
        $demande->gestionnaire_id = $gestionnaire->id;
        $demande->date_verif_rh = now();

        if ($decision === 'rejeter') {
            $demande->statut = DemandePermission::REJETEE;
            $demande->motif_rejet = $motif;
            $demande->save();
            self::tracer($demande, $gestionnaire, 'ROLE_GESTIONNAIRE_RH', 'REJET_RH', $ancien, $demande->statut, $motif);
            self::notifier($demande->agent_id, 'Demande rejetée par le gestionnaire RH',
                "Votre demande {$demande->code_dossier} est rejetée : {$motif}", 'REJET', $demande->code_dossier);

            return $demande;
        }

        if ($decision === 'corriger') {
            $demande->statut = DemandePermission::RETOUR_CORRECTION;
            $demande->motif_retour = $motif;
            $demande->save();
            self::tracer($demande, $gestionnaire, 'ROLE_GESTIONNAIRE_RH', 'RETOUR_CORRECTION', $ancien, $demande->statut, $motif);
            self::notifier($demande->agent_id, 'Demande à corriger',
                "Votre demande {$demande->code_dossier} est retournée pour correction : {$motif}", 'RETOUR', $demande->code_dossier);

            return $demande;
        }

        $demande->avis_gestionnaire = 'CONFORME';

        if ($demande->isCircuitCourt()) {
            [$direction, $visa] = self::directionPour($demande->agent);
            $demande->visa_attendu = $visa;
            $demande->statut = $visa === 'SOUS_DIRECTEUR'
                ? DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR
                : DemandePermission::EN_ATTENTE_VISA_DIRECTEUR;
            $demande->motif_rejet = null;
            $demande->save();
            self::tracer($demande, $gestionnaire, 'ROLE_GESTIONNAIRE_RH', 'TRANSMISSION_VISA', $ancien, $demande->statut,
                "Dossier conforme (≤ 2 j), transmis au {$visa}.");
            if ($direction) {
                self::notifier($direction->id, 'Visa hiérarchique requis',
                    "Demande {$demande->code_dossier} (≤ 2 j) conforme, votre visa est requis.", 'ATTENTE_VISA', $demande->code_dossier);
            }
        } else {
            $demande->statut = DemandePermission::EN_ATTENTE_DRH;
            $demande->motif_rejet = null;
            $demande->save();
            self::tracer($demande, $gestionnaire, 'ROLE_GESTIONNAIRE_RH', 'TRANSMISSION_DRH', $ancien, $demande->statut,
                'Dossier conforme (> 2 j), transmis directement au DRH.');
            $drh = self::drh();
            if ($drh) {
                self::notifier($drh->id, 'Demande > 2 jours à trancher',
                    "Demande {$demande->code_dossier} vérifiée conforme, décision DRH requise.", 'ATTENTE_DRH', $demande->code_dossier);
            }
        }

        return $demande;
    }

    // ---------------------------------------------------------------
    // SOUS-DIRECTEUR / DIRECTEUR : visa (cas 1 uniquement)
    // ---------------------------------------------------------------
    public static function viser(DemandePermission $demande, Agent $directeur, string $roleActeur, bool $favorable, ?string $motif = null): DemandePermission
    {
        $attendus = [
            'SOUS_DIRECTEUR' => DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR,
            'DIRECTEUR' => DemandePermission::EN_ATTENTE_VISA_DIRECTEUR,
        ];
        if (! isset($attendus[$roleActeur]) || $demande->statut !== $attendus[$roleActeur]) {
            abort(422, 'Visa impossible : la demande ne relève pas de ce niveau hiérarchique.');
        }
        if (! $favorable && blank($motif)) {
            abort(422, 'Motif obligatoire en cas de refus de visa.');
        }

        $ancien = $demande->statut;
        $demande->visa_direction_id = $directeur->id;
        $demande->date_visa = now();

        if (! $favorable) {
            $demande->statut = DemandePermission::REJETEE;
            $demande->avis_direction = 'DEFAVORABLE';
            $demande->motif_rejet = $motif;
            $demande->save();
            self::tracer($demande, $directeur, 'ROLE_'.strtoupper($roleActeur), 'REFUS_VISA', $ancien, $demande->statut, $motif);
            self::notifier($demande->agent_id, 'Visa hiérarchique refusé',
                "Votre demande {$demande->code_dossier} a reçu un avis défavorable : {$motif}", 'REJET', $demande->code_dossier);

            return $demande;
        }

        $demande->avis_direction = 'FAVORABLE';
        $demande->statut = DemandePermission::EN_ATTENTE_DRH;
        $demande->save();
        self::tracer($demande, $directeur, 'ROLE_'.strtoupper($roleActeur), 'VISA_FAVORABLE', $ancien, $demande->statut,
            'Visa accordé, dossier transmis au DRH.');

        $drh = self::drh();
        if ($drh) {
            self::notifier($drh->id, 'Dossier visé à valider',
                "Dossier {$demande->code_dossier} visé favorablement, validation DRH requise.", 'ATTENTE_DRH', $demande->code_dossier);
        }

        return $demande;
    }

    // ---------------------------------------------------------------
    // DRH : décision finale (puis retour au gestionnaire pour notifier)
    // ---------------------------------------------------------------
    public static function trancherDrh(DemandePermission $demande, Agent $drh, bool $valide, ?string $motif = null): DemandePermission
    {
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
            $agent->solde_permission_annuel = max(0, $agent->solde_permission_annuel - $demande->nombre_jours);
            $agent->save();
        }

        self::tracer($demande, $drh, 'ROLE_DRH', $valide ? 'VALIDATION_DRH' : 'REJET_DRH', $ancien, $demande->statut,
            $valide ? 'Demande validée.' : $motif);

        $gestionnaire = $demande->gestionnaire_id
            ? Agent::find($demande->gestionnaire_id)
            : self::gestionnairePour($demande->agent);
        if ($gestionnaire) {
            self::notifier($gestionnaire->id, 'Décision DRH à notifier',
                "Le DRH a tranché le dossier {$demande->code_dossier} ({$demande->statut}). À notifier à l'agent.",
                'A_NOTIFIER', $demande->code_dossier);
        }

        return $demande;
    }

    // ---------------------------------------------------------------
    // GESTIONNAIRE RH : notifier l'agent de la décision finale
    // ---------------------------------------------------------------
    public static function notifierAgent(DemandePermission $demande, Agent $gestionnaire): DemandePermission
    {
        if (! in_array($demande->statut, [DemandePermission::VALIDEE, DemandePermission::REJETEE], true)) {
            abort(422, 'Aucune décision finale à notifier.');
        }
        if ($demande->notifie_le !== null) {
            abort(422, 'Agent déjà notifié.');
        }

        $demande->notifie_le = now();
        $demande->notifie_par_id = $gestionnaire->id;
        $demande->save();

        $valide = $demande->statut === DemandePermission::VALIDEE;
        $demande->loadMissing('type');
        $type = $demande->type?->libelle ?? 'Permission';
        $periode = $demande->date_debut?->format('d/m/Y').' au '.$demande->date_fin?->format('d/m/Y');
        $dateDecision = $demande->date_decision?->format('d/m/Y à H:i') ?? now()->format('d/m/Y à H:i');
        self::tracer($demande, $gestionnaire, 'ROLE_GESTIONNAIRE_RH', 'NOTIFICATION_AGENT', $demande->statut, $demande->statut,
            $valide ? "Acceptation notifiée à l'agent." : "Rejet notifié : {$demande->motif_rejet}");
        self::notifier($demande->agent_id,
            $valide ? 'Demande acceptée' : 'Demande rejetée',
            $valide
                ? "Votre demande {$demande->code_dossier} ({$type}, {$periode}) a été VALIDÉE par le DRH le {$dateDecision}."
                : "Votre demande {$demande->code_dossier} ({$type}, {$periode}) a été REJETÉE par le DRH le {$dateDecision}. Motif : {$demande->motif_rejet}",
            $valide ? 'VALIDATION' : 'REJET',
            $demande->code_dossier);

        return $demande;
    }

    // ---------------------------------------------------------------
    // Résolution automatique des acteurs (structures, rôles)
    // ---------------------------------------------------------------
    public static function gestionnairePour(Agent $agent): ?Agent
    {
        $base = Agent::whereHas('user.roles', fn ($q) => $q->where('code', 'ROLE_GESTIONNAIRE_RH'));

        return (clone $base)->where('structure_id', $agent->structure_id)->first()
            ?? (clone $base)->whereHas('structure', fn ($q) => $q->where('code', 'DRH'))->first()
            ?? $base->first();
    }

    /**
     * @return array{0: ?Agent, 1: 'SOUS_DIRECTEUR'|'DIRECTEUR'}
     */
    public static function directionPour(Agent $agent): array
    {
        $estSousDirection = $agent->structure?->type === 'Sous-Direction';
        $code = $estSousDirection ? 'ROLE_SOUS_DIRECTEUR' : 'ROLE_DIRECTEUR';
        $visa = $estSousDirection ? 'SOUS_DIRECTEUR' : 'DIRECTEUR';

        // Responsable désigné de la structure en priorité, sinon même
        // structure, sinon n'importe quel titulaire du rôle.
        $responsable = $agent->structure?->responsable;
        if ($responsable && $responsable->user?->hasRole($code)) {
            return [$responsable, $visa];
        }

        $candidats = Agent::whereHas('user.roles', fn ($q) => $q->where('code', $code));
        $choisi = (clone $candidats)->where('structure_id', $agent->structure_id)->first()
            ?? $candidats->first();

        return [$choisi, $visa];
    }

    public static function drh(): ?Agent
    {
        $base = Agent::whereHas('user.roles', fn ($q) => $q->where('code', 'ROLE_DRH'));

        return (clone $base)->whereHas('structure', fn ($q) => $q->where('code', 'DRH'))->first()
            ?? $base->first();
    }

    // ---------------------------------------------------------------
    // Traçabilité & notifications
    // ---------------------------------------------------------------
    public static function tracer(DemandePermission $demande, ?Agent $acteur, ?string $role, string $action, ?string $ancien, ?string $nouveau, ?string $commentaire = null): void
    {
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
    }

    protected static function notifier(int $agentId, string $titre, string $message, string $type, ?string $ref): void
    {
        Notification::create([
            'agent_id' => $agentId, 'titre' => $titre, 'message' => $message, 'type' => $type, 'reference_dossier' => $ref,
        ]);
    }
}
