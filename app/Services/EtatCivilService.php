<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\JournalAudit;
use App\Models\DeclarationDeces;
use App\Models\DeclarationHistorique;
use App\Models\DeclarationNaissance;
use App\Models\Notification;
use Illuminate\Support\Str;

/**
 * Workflow des déclarations d'état civil (naissance & décès), indépendant
 * des permissions :
 * AGENT → GESTIONNAIRE RH (vérification) → DRH (validation) → AGENT.
 */
class EtatCivilService
{
    /** Rôles qui suivent toutes les déclarations (les autres ne voient que les leurs). */
    public const ROLES_SUIVI = ['ROLE_GESTIONNAIRE_RH', 'ROLE_DRH', 'ROLE_ADMIN_DSI'];

    public const EN_ATTENTE_GESTIONNAIRE_RH = 'EN_ATTENTE_GESTIONNAIRE_RH';

    public const EN_ATTENTE_SERVICE = self::EN_ATTENTE_GESTIONNAIRE_RH;

    public const RETOUR_CORRECTION = 'RETOUR_CORRECTION';

    public const EN_ATTENTE_RH = 'EN_ATTENTE_RH';

    public const VALIDEE = 'VALIDEE';

    public const REJETEE = 'REJETEE';

    public const BROUILLON = 'BROUILLON';

    public const ARCHIVEE = 'ARCHIVEE';

    private const MODELES = [
        'NAISSANCE' => DeclarationNaissance::class,
        'DECES' => DeclarationDeces::class,
    ];

    // ---------------------------------------------------------------
    // AGENT : déclarer (brouillon ou soumission directe)
    // ---------------------------------------------------------------
    public static function declarer(string $type, Agent $agent, array $data, ?string $piecePath): DeclarationNaissance|DeclarationDeces
    {
        $modele = self::MODELES[$type];
        $soumettre = $data['soumettre'] ?? true;

        $communs = [
            'code_dossier' => ($type === 'NAISSANCE' ? 'NAISS' : 'DECES').'-'.now()->year.'-'.strtoupper(Str::random(6)),
            'agent_id' => $agent->id,
            'statut' => $soumettre ? self::EN_ATTENTE_GESTIONNAIRE_RH : self::BROUILLON,
        ];

        $declaration = $type === 'NAISSANCE'
            ? $modele::create($communs + [
                'nom_enfant' => strtoupper(trim($data['nom_enfant'])),
                'prenom_enfant' => trim($data['prenom_enfant']),
                'date_naissance_enfant' => $data['date_naissance_enfant'],
                'lieu_naissance_enfant' => $data['lieu_naissance_enfant'],
                'extrait_path' => $piecePath,
            ])
            : $modele::create($communs + [
                'nom_defunt' => strtoupper(trim($data['nom_defunt'])),
                'prenom_defunt' => trim($data['prenom_defunt']),
                'lien_parente' => $data['lien_parente'],
                'date_deces' => $data['date_deces'],
                'lieu_deces' => $data['lieu_deces'],
                'certificat_path' => $piecePath,
            ]);

        self::tracer($type, $declaration, $agent, 'ROLE_AGENT',
            $soumettre ? 'SOUMISSION' : 'BROUILLON', null, $declaration->statut,
            $soumettre ? 'Déclaration transmise au Gestionnaire RH.' : 'Brouillon enregistré.');

        if ($soumettre) {
            self::avertirGestionnaire($type, $declaration, $agent);
        }

        return $declaration;
    }

    public static function soumettreBrouillon(string $type, DeclarationNaissance|DeclarationDeces $declaration, Agent $agent): DeclarationNaissance|DeclarationDeces
    {
        if ($declaration->statut !== self::BROUILLON) {
            abort(422, 'Seul un brouillon peut être soumis.');
        }
        if ($declaration->agent_id !== $agent->id) {
            abort(403, 'Seul le déclarant peut soumettre son dossier.');
        }

        $ancien = $declaration->statut;
        $declaration->update(['statut' => self::EN_ATTENTE_GESTIONNAIRE_RH]);
        self::tracer($type, $declaration, $agent, 'ROLE_AGENT', 'SOUMISSION', $ancien, $declaration->statut,
            'Brouillon soumis au Gestionnaire RH.');
        self::avertirGestionnaire($type, $declaration, $agent);

        return $declaration->refresh();
    }

    // ---------------------------------------------------------------
    // GESTIONNAIRE RH : vérifier complétude et justificatifs (conforme / retourner)
    // ---------------------------------------------------------------
    /**
     * @param  'conforme'|'retourner'  $decision
     */
    public static function controler(string $type, DeclarationNaissance|DeclarationDeces $declaration, Agent $controleur, string $decision, ?string $motif = null): DeclarationNaissance|DeclarationDeces
    {
        if (! in_array($declaration->statut, [self::EN_ATTENTE_GESTIONNAIRE_RH, self::EN_ATTENTE_SERVICE], true)) {
            abort(422, 'Vérification impossible à ce stade.');
        }
        if ($decision === 'retourner' && blank($motif)) {
            abort(422, 'Motif obligatoire pour un retour en correction.');
        }

        $ancien = $declaration->statut;

        if ($decision === 'retourner') {
            $declaration->update(['statut' => self::RETOUR_CORRECTION, 'motif_retour' => $motif]);
            self::tracer($type, $declaration, $controleur, 'ROLE_GESTIONNAIRE_RH', 'RETOUR_CORRECTION', $ancien, $declaration->statut, $motif);
            self::notifier($declaration->agent_id, 'Dossier incomplet à corriger',
                "Votre déclaration {$declaration->code_dossier} est retournée par le Gestionnaire RH : {$motif}", 'RETOUR', $declaration->code_dossier);

            return $declaration->refresh();
        }

        $declaration->update(['statut' => self::EN_ATTENTE_RH, 'motif_retour' => null]);
        self::tracer($type, $declaration, $controleur, 'ROLE_GESTIONNAIRE_RH', 'VERIFICATION_CONFORME', $ancien, $declaration->statut,
            'Dossier complet et pièces conformes, transmis à la DRH.');

        $drh = PermissionWorkflowService::drh();
        if ($drh) {
            self::notifier($drh->id, 'Acte à valider',
                "Déclaration {$declaration->code_dossier} vérifiée par le Gestionnaire RH, décision DRH requise.", 'ATTENTE_DRH', $declaration->code_dossier);
        }
        self::notifier($declaration->agent_id, 'Dossier transmis à la DRH',
            "Votre déclaration {$declaration->code_dossier} a été vérifiée avec succès par le Gestionnaire RH et transmise à la DRH.", 'INFO', $declaration->code_dossier);

        return $declaration->refresh();
    }

    // ---------------------------------------------------------------
    // AGENT : corriger un dossier retourné
    // ---------------------------------------------------------------
    /** Contrôle à faire avant tout dépôt de pièce : dossier retourné, corrigé par son déclarant. */
    public static function exigerCorrigeable(DeclarationNaissance|DeclarationDeces $declaration, ?Agent $agent): void
    {
        if ($declaration->statut !== self::RETOUR_CORRECTION) {
            abort(422, 'Seul un dossier retourné peut être corrigé.');
        }
        if ($agent === null || $declaration->agent_id !== $agent->id) {
            abort(403, 'Seul le déclarant peut corriger son dossier.');
        }
    }

    public static function corriger(string $type, DeclarationNaissance|DeclarationDeces $declaration, Agent $agent, array $data, ?string $piecePath): DeclarationNaissance|DeclarationDeces
    {
        self::exigerCorrigeable($declaration, $agent);
        if ($type === 'NAISSANCE' && isset($data['nom_enfant']) && blank($piecePath) && blank($declaration->extrait_path)) {
            abort(422, "L'extrait d'acte de naissance reste obligatoire.");
        }
        if ($type === 'DECES' && isset($data['nom_defunt']) && blank($piecePath) && blank($declaration->certificat_path)) {
            abort(422, 'Le certificat de décès officiel reste obligatoire.');
        }

        $ancien = $declaration->statut;
        $maj = ['statut' => self::EN_ATTENTE_GESTIONNAIRE_RH, 'motif_retour' => null];
        foreach (['nom_enfant', 'prenom_enfant', 'date_naissance_enfant', 'lieu_naissance_enfant',
            'nom_defunt', 'prenom_defunt', 'lien_parente', 'date_deces', 'lieu_deces'] as $champ) {
            if (array_key_exists($champ, $data) && $data[$champ] !== null) {
                $maj[$champ] = in_array($champ, ['nom_enfant', 'nom_defunt'], true)
                    ? strtoupper(trim($data[$champ]))
                    : $data[$champ];
            }
        }
        if ($piecePath !== null) {
            $maj[$type === 'NAISSANCE' ? 'extrait_path' : 'certificat_path'] = $piecePath;
        }
        $declaration->update($maj);

        self::tracer($type, $declaration, $agent, 'ROLE_AGENT', 'CORRECTION', $ancien, $declaration->statut,
            'Dossier corrigé et renvoyé au Gestionnaire RH.');
        self::avertirGestionnaire($type, $declaration, $agent);

        return $declaration->refresh();
    }

    // ---------------------------------------------------------------
    // DRH : valider / rejeter / archiver
    // ---------------------------------------------------------------
    public static function trancher(string $type, DeclarationNaissance|DeclarationDeces $declaration, Agent $drh, bool $valide, ?string $motif = null): DeclarationNaissance|DeclarationDeces
    {
        if ($declaration->statut !== self::EN_ATTENTE_RH) {
            abort(422, 'Décision DRH impossible à ce stade.');
        }
        if (! $valide && blank($motif)) {
            abort(422, 'Motif obligatoire en cas de rejet.');
        }

        $ancien = $declaration->statut;
        $declaration->update([
            'statut' => $valide ? self::VALIDEE : self::REJETEE,
            'motif_rejet' => $valide ? null : $motif,
            'valideur_id' => $drh->id,
            'validated_at' => now(),
        ]);
        self::tracer($type, $declaration, $drh, 'ROLE_DRH', $valide ? 'VALIDATION' : 'REJET', $ancien, $declaration->statut,
            $valide ? 'Déclaration validée, dossier statutaire mis à jour.' : $motif);
        self::notifier($declaration->agent_id,
            $valide ? 'Déclaration validée' : 'Déclaration rejetée',
            $valide
                ? "Votre déclaration {$declaration->code_dossier} a été validée par la DRH."
                : "Votre déclaration {$declaration->code_dossier} a été rejetée par la DRH : {$motif}",
            $valide ? 'VALIDATION' : 'REJET',
            $declaration->code_dossier);

        return $declaration->refresh();
    }

    public static function archiver(string $type, DeclarationNaissance|DeclarationDeces $declaration, Agent $drh): DeclarationNaissance|DeclarationDeces
    {
        if (! in_array($declaration->statut, [self::VALIDEE, self::REJETEE], true)) {
            abort(422, 'Seul un dossier clôturé peut être archivé.');
        }
        $ancien = $declaration->statut;
        $declaration->update(['statut' => self::ARCHIVEE]);
        self::tracer($type, $declaration, $drh, 'ROLE_DRH', 'ARCHIVAGE', $ancien, $declaration->statut, 'Dossier archivé.');

        return $declaration->refresh();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------
    public static function modele(string $type): string
    {
        return self::MODELES[$type];
    }

    protected static function avertirGestionnaire(string $type, DeclarationNaissance|DeclarationDeces $declaration, Agent $agent): void
    {
        $libelle = $type === 'NAISSANCE' ? 'naissance' : 'décès';
        $gestionnaire = PermissionWorkflowService::gestionnairePour($agent);
        if ($gestionnaire) {
            self::notifier($gestionnaire->id, "Déclaration de {$libelle} à vérifier",
                "Nouvelle déclaration {$declaration->code_dossier} soumise par {$agent->fullName()}.",
                'ATTENTE_CONTROLE', $declaration->code_dossier);
        }
        self::notifier($agent->id, 'Déclaration transmise',
            "Votre déclaration {$declaration->code_dossier} a été transmise au Gestionnaire RH pour vérification.", 'INFO', $declaration->code_dossier);
    }

    public static function tracer(string $type, DeclarationNaissance|DeclarationDeces $declaration, ?Agent $acteur, ?string $role, string $action, ?string $ancien, ?string $nouveau, ?string $commentaire = null): void
    {
        DeclarationHistorique::create([
            'type_dossier' => $type,
            'dossier_id' => $declaration->id,
            'code_dossier' => $declaration->code_dossier,
            'acteur_agent_id' => $acteur?->id,
            'acteur_nom' => $acteur?->fullName(),
            'acteur_role' => $role,
            'action' => $action,
            'ancien_statut' => $ancien,
            'nouveau_statut' => $nouveau,
            'commentaire' => $commentaire,
        ]);
        JournalAudit::noter(JournalAudit::DOSSIER, $action, $acteur?->user, trim(($role ? "[{$role}] " : '').($ancien || $nouveau ? "{$ancien} → {$nouveau}" : '').($commentaire ? " — {$commentaire}" : '')), $declaration->code_dossier);
    }

    protected static function notifier(int $agentId, string $titre, string $message, string $type, ?string $ref): void
    {
        Notification::create([
            'agent_id' => $agentId, 'titre' => $titre, 'message' => $message, 'type' => $type, 'reference_dossier' => $ref,
        ]);
    }
}
