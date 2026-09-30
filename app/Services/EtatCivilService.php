<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\DeclarationDeces;
use App\Models\DeclarationHistorique;
use App\Models\DeclarationNaissance;
use App\Models\JournalAudit;
use App\Models\Notification;
use Illuminate\Support\Str;

/** Naissances : Agent (brouillon ou soumission, extrait obligatoire) → Gestionnaire RH (conforme ou
 * retour) → DRH (valide, rejette ou retourne) → Agent notifié. */
class EtatCivilService
{
    /** Rôles qui suivent toutes les déclarations (les autres ne voient que les leurs). */
    public const ROLES_SUIVI = [
        'ROLE_GESTIONNAIRE_RH',
        'ROLE_SOUS_DIRECTEUR',
        'ROLE_DIRECTEUR',
        'ROLE_DRH',
        'ROLE_ADMIN_DSI',
    ];

    /** Responsable concerné pour les naissances (validation intermédiaire). */
    public const ROLES_RESPONSABLE = ['ROLE_SOUS_DIRECTEUR', 'ROLE_DIRECTEUR'];

    /** Module naissance : boîte Gestionnaire RH (soumission initiale). */
    public const EN_ATTENTE_RH = 'EN_ATTENTE_RH';

    /** Alias historique (ancien contrat) — même valeur, pour conserver les données. */
    public const EN_ATTENTE_GESTIONNAIRE_RH = self::EN_ATTENTE_RH;

    public const RETOUR_CORRECTION = 'RETOUR_CORRECTION';

    /** Module naissance : attente validation du responsable concerné. */
    public const EN_ATTENTE_VALIDATION_RESPONSABLE = 'EN_ATTENTE_VALIDATION_RESPONSABLE';

    /** Module naissance/décès : attente décision finale DRH. */
    public const EN_ATTENTE_DRH = 'EN_ATTENTE_DRH';

    public const VALIDEE = 'VALIDEE';

    public const REJETEE = 'REJETEE';

    public const BROUILLON = 'BROUILLON';

    public const ARCHIVEE = 'ARCHIVEE';

    /** Statuts exacts du module naissance (séparé des permissions). */
    public const STATUTS_NAISSANCE = [
        self::BROUILLON,
        self::EN_ATTENTE_RH,
        self::RETOUR_CORRECTION,
        self::EN_ATTENTE_VALIDATION_RESPONSABLE,
        self::EN_ATTENTE_DRH,
        self::VALIDEE,
        self::REJETEE,
    ];

    private const MODELES = [
        'NAISSANCE' => DeclarationNaissance::class,
        'DECES' => DeclarationDeces::class,
    ];

    // AGENT : déclarer (brouillon ou soumission directe)
    public static function declarer(
        string $type,
        Agent $agent,
        array $data,
        ?string $piecePath,
    ): DeclarationNaissance|DeclarationDeces {
        $modele = self::MODELES[$type];
        $soumettre = $data['soumettre'] ?? true;

        // AGENT : le justificatif officiel est obligatoire pour soumettre
        // (extrait d'acte de naissance / certificat de décès officiel).
        if ($soumettre && blank($piecePath)) {
            abort(
                422,
                $type === 'NAISSANCE'
                    ? "L'extrait d'acte de naissance est obligatoire."
                    : 'Le certificat de décès officiel est obligatoire.',
            );
        }

        // Un sous-directeur ou un directeur ne peut pas faire viser sa déclaration
        // par sa propre structure : elle part directement au DRH.
        $circuitDrhDirect = $soumettre && $agent->autoriteVisa();

        $communs = [
            'code_dossier' => ($type === 'NAISSANCE' ? 'NAISS' : 'DECES').
                '-'.
                now()->year.
                '-'.
                strtoupper(Str::random(6)),
            'agent_id' => $agent->id,
            'statut' => match (true) {
                ! $soumettre => self::BROUILLON,
                $circuitDrhDirect => self::EN_ATTENTE_DRH,
                default => self::EN_ATTENTE_RH,
            },
        ];

        $declaration =
            $type === 'NAISSANCE'
                ? $modele::create(
                    $communs + [
                        'nom_enfant' => strtoupper(trim($data['nom_enfant'])),
                        'prenom_enfant' => trim($data['prenom_enfant']),
                        'date_naissance_enfant' => $data['date_naissance_enfant'],
                        'lieu_naissance_enfant' => $data['lieu_naissance_enfant'],
                        'extrait_path' => $piecePath,
                    ],
                )
                : $modele::create(
                    $communs + [
                        'nom_defunt' => strtoupper(trim($data['nom_defunt'])),
                        'prenom_defunt' => trim($data['prenom_defunt']),
                        'lien_parente' => $data['lien_parente'],
                        'date_deces' => $data['date_deces'],
                        'lieu_deces' => $data['lieu_deces'],
                        'certificat_path' => $piecePath,
                    ],
                );

        self::tracer(
            $type,
            $declaration,
            $agent,
            self::roleActeur($agent, $circuitDrhDirect),
            $soumettre ? 'SOUMISSION' : 'BROUILLON',
            null,
            $declaration->statut,
            match (true) {
                ! $soumettre => 'Brouillon enregistré.',
                $circuitDrhDirect => 'Déclaration transmise directement au DRH (déclarant responsable).',
                default => 'Déclaration transmise au Gestionnaire RH.',
            },
        );

        if (! $soumettre) {
            return $declaration;
        }

        if ($circuitDrhDirect) {
            self::avertirDrh($type, $declaration, $agent);
        } else {
            self::avertirGestionnaire($type, $declaration, $agent);
        }

        return $declaration;
    }

    /** Agent : soumet au gestionnaire RH une déclaration restée en brouillon. Seul le déclarant peut la
     * soumettre. */
    public static function soumettreBrouillon(
        string $type,
        DeclarationNaissance|DeclarationDeces $declaration,
        Agent $agent,
    ): DeclarationNaissance|DeclarationDeces {
        if ($declaration->statut !== self::BROUILLON) {
            abort(422, 'Seul un brouillon peut être soumis.');
        }
        if ($declaration->agent_id !== $agent->id) {
            abort(403, 'Seul le déclarant peut soumettre son dossier.');
        }
        // Le justificatif officiel reste obligatoire pour entrer dans le circuit.
        $piecePresente = $type === 'NAISSANCE'
            ? ! blank($declaration->extrait_path)
            : ! blank($declaration->certificat_path);
        if (! $piecePresente) {
            abort(
                422,
                $type === 'NAISSANCE'
                    ? "L'extrait d'acte de naissance est obligatoire pour soumettre."
                    : 'Le certificat de décès officiel est obligatoire pour soumettre.',
            );
        }

        $circuitDrhDirect = $agent->autoriteVisa();

        $ancien = $declaration->statut;
        $declaration->update([
            'statut' => $circuitDrhDirect ? self::EN_ATTENTE_DRH : self::EN_ATTENTE_RH,
        ]);
        self::tracer(
            $type,
            $declaration,
            $agent,
            self::roleActeur($agent, $circuitDrhDirect),
            'SOUMISSION',
            $ancien,
            $declaration->statut,
            $circuitDrhDirect
                ? 'Brouillon soumis directement au DRH (déclarant responsable).'
                : 'Brouillon soumis au Gestionnaire RH.',
        );

        if ($circuitDrhDirect) {
            self::avertirDrh($type, $declaration, $agent);
        } else {
            self::avertirGestionnaire($type, $declaration, $agent);
        }

        return $declaration->refresh();
    }

    /** Rôle porté dans l'historique : un sous-directeur ou un directeur agit sous son propre rôle, pas
     * sous celui d'un agent simple. */
    protected static function roleActeur(Agent $agent, bool $circuitDrhDirect): string
    {
        return $circuitDrhDirect ? 'ROLE_'.strtoupper($agent->roleVisa()) : 'ROLE_AGENT';
    }

    // GESTIONNAIRE RH : vérifier complétude et justificatifs (conforme / retourner)
    /** @param 'conforme'|'retourner' $decision */
    public static function controler(
        string $type,
        DeclarationNaissance|DeclarationDeces $declaration,
        Agent $controleur,
        string $decision,
        ?string $motif = null,
    ): DeclarationNaissance|DeclarationDeces {
        // GESTIONNAIRE RH uniquement : boîte EN_ATTENTE_RH (soumission initiale).
        if ($declaration->statut !== self::EN_ATTENTE_RH) {
            abort(422, 'Vérification impossible à ce stade.');
        }
        if ($decision === 'retourner' && blank($motif)) {
            abort(422, 'Motif obligatoire pour un retour en correction.');
        }
        // Si le dossier est déclaré conforme, le justificatif doit être présent.
        if ($decision !== 'retourner') {
            $piecePresente = $type === 'NAISSANCE'
                ? ! blank($declaration->extrait_path)
                : ! blank($declaration->certificat_path);
            if (! $piecePresente) {
                abort(
                    422,
                    $type === 'NAISSANCE'
                        ? "Dossier incomplet : l'extrait d'acte de naissance est manquant."
                        : 'Dossier incomplet : le certificat de décès officiel est manquant.',
                );
            }
        }

        $ancien = $declaration->statut;

        if ($decision === 'retourner') {
            $declaration->update(['statut' => self::RETOUR_CORRECTION, 'motif_retour' => $motif]);
            self::tracer(
                $type,
                $declaration,
                $controleur,
                'ROLE_GESTIONNAIRE_RH',
                'RETOUR_CORRECTION',
                $ancien,
                $declaration->statut,
                $motif,
            );
            self::notifier(
                $declaration->agent_id,
                'Dossier incomplet à corriger',
                "Votre déclaration {$declaration->code_dossier} est retournée par le Gestionnaire RH : {$motif}",
                'RETOUR',
                $declaration->code_dossier,
            );

            return $declaration->refresh();
        }

        // Conforme → DRH direct, pour les naissances comme pour les décès : c'est
        // le DRH qui tranche, sans visa intermédiaire.
        $declaration->update(['statut' => self::EN_ATTENTE_DRH, 'motif_retour' => null]);
        self::tracer(
            $type,
            $declaration,
            $controleur,
            'ROLE_GESTIONNAIRE_RH',
            'VERIFICATION_CONFORME',
            $ancien,
            $declaration->statut,
            'Dossier complet et pièces conformes, transmis à la DRH.',
        );

        self::notifier(
            $declaration->agent_id,
            'Dossier transmis à la DRH',
            "Votre déclaration {$declaration->code_dossier} a été vérifiée avec succès par le Gestionnaire RH et transmise à la DRH.",
            'INFO',
            $declaration->code_dossier,
        );

        $drh = self::drh();
        if ($drh) {
            self::notifier(
                $drh->id,
                'Acte à valider',
                "Déclaration {$declaration->code_dossier} vérifiée par le Gestionnaire RH, décision DRH requise.",
                'ATTENTE_DRH',
                $declaration->code_dossier,
            );
        }

        return $declaration->refresh();
    }

    // AGENT : corriger un dossier retourné
    /** Contrôle à faire avant tout dépôt de pièce : dossier retourné, corrigé par son déclarant. */
    public static function exigerCorrigeable(
        DeclarationNaissance|DeclarationDeces $declaration,
        ?Agent $agent,
    ): void {
        if ($declaration->statut !== self::RETOUR_CORRECTION) {
            abort(422, 'Seul un dossier retourné peut être corrigé.');
        }
        if ($agent === null || $declaration->agent_id !== $agent->id) {
            abort(403, 'Seul le déclarant peut corriger son dossier.');
        }
    }

    /** Agent : corrige et renvoie une déclaration retournée. La pièce officielle reste obligatoire ; le
     * dossier revient au gestionnaire RH. */
    public static function corriger(
        string $type,
        DeclarationNaissance|DeclarationDeces $declaration,
        Agent $agent,
        array $data,
        ?string $piecePath,
    ): DeclarationNaissance|DeclarationDeces {
        self::exigerCorrigeable($declaration, $agent);
        // La pièce officielle reste obligatoire : existante ou nouvellement jointe.
        $pieceExistante = $type === 'NAISSANCE'
            ? $declaration->extrait_path
            : $declaration->certificat_path;
        if (blank($piecePath) && blank($pieceExistante)) {
            abort(
                422,
                $type === 'NAISSANCE'
                    ? "L'extrait d'acte de naissance reste obligatoire."
                    : 'Le certificat de décès officiel reste obligatoire.',
            );
        }

        $ancien = $declaration->statut;
        $maj = ['statut' => self::EN_ATTENTE_RH, 'motif_retour' => null];
        foreach (
            [
                'nom_enfant',
                'prenom_enfant',
                'date_naissance_enfant',
                'lieu_naissance_enfant',
                'nom_defunt',
                'prenom_defunt',
                'lien_parente',
                'date_deces',
                'lieu_deces',
            ] as $champ
        ) {
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

        self::tracer(
            $type,
            $declaration,
            $agent,
            'ROLE_AGENT',
            'CORRECTION',
            $ancien,
            $declaration->statut,
            'Dossier corrigé et renvoyé au Gestionnaire RH.',
        );
        self::avertirGestionnaire($type, $declaration, $agent);

        return $declaration->refresh();
    }

    // DRH : valider / rejeter / archiver DRH : validation finale (EN_ATTENTE_DRH → VALIDEE / REJETEE +
    // notif agent)
    public static function trancher(
        string $type,
        DeclarationNaissance|DeclarationDeces $declaration,
        Agent $drh,
        bool $valide,
        ?string $motif = null,
    ): DeclarationNaissance|DeclarationDeces {
        if ($declaration->statut !== self::EN_ATTENTE_DRH) {
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
        self::tracer(
            $type,
            $declaration,
            $drh,
            'ROLE_DRH',
            $valide ? 'VALIDATION' : 'REJET',
            $ancien,
            $declaration->statut,
            $valide ? 'Déclaration validée, dossier statutaire mis à jour.' : $motif,
        );
        self::notifier(
            $declaration->agent_id,
            $valide ? 'Déclaration validée' : 'Déclaration rejetée',
            $valide
                ? "Votre déclaration {$declaration->code_dossier} a été validée par la DRH."
                : "Votre déclaration {$declaration->code_dossier} a été rejetée par la DRH : {$motif}",
            $valide ? 'VALIDATION' : 'REJET',
            $declaration->code_dossier,
        );

        return $declaration->refresh();
    }

    /** DRH : retourne la déclaration pour correction (au lieu de la rejeter). L'agent la corrige puis la
     * resoumet, et le circuit reprend à la vérification RH. */
    public static function retourner(
        string $type,
        DeclarationNaissance|DeclarationDeces $declaration,
        Agent $drh,
        string $motif,
    ): DeclarationNaissance|DeclarationDeces {
        if ($declaration->statut !== self::EN_ATTENTE_DRH) {
            abort(422, 'Retour impossible à ce stade.');
        }
        if (blank($motif)) {
            abort(422, 'Motif obligatoire pour un retour en correction.');
        }

        $ancien = $declaration->statut;
        $declaration->update([
            'statut' => self::RETOUR_CORRECTION,
            'motif_retour' => $motif,
            'valideur_id' => $drh->id,
            'validated_at' => now(),
        ]);
        self::tracer(
            $type,
            $declaration,
            $drh,
            'ROLE_DRH',
            'RETOUR',
            $ancien,
            $declaration->statut,
            $motif,
        );
        self::notifier(
            $declaration->agent_id,
            'Déclaration retournée pour correction',
            "Votre déclaration {$declaration->code_dossier} a été retournée par la DRH pour correction : {$motif}",
            'RETOUR_CORRECTION',
            $declaration->code_dossier,
        );

        return $declaration->refresh();
    }

    /** DRH : archive une déclaration clôturée (validée ou rejetée). Le dossier reste consultable. */
    public static function archiver(
        string $type,
        DeclarationNaissance|DeclarationDeces $declaration,
        Agent $drh,
    ): DeclarationNaissance|DeclarationDeces {
        if (! in_array($declaration->statut, [self::VALIDEE, self::REJETEE], true)) {
            abort(422, 'Seul un dossier clôturé peut être archivé.');
        }
        $ancien = $declaration->statut;
        $declaration->update(['statut' => self::ARCHIVEE]);
        self::tracer(
            $type,
            $declaration,
            $drh,
            'ROLE_DRH',
            'ARCHIVAGE',
            $ancien,
            $declaration->statut,
            'Dossier archivé.',
        );

        return $declaration->refresh();
    }

    // Helpers
    public static function modele(string $type): string
    {
        return self::MODELES[$type];
    }

    /** Gestionnaire RH destinataire d'une déclaration : celui de la structure de l'agent, sinon de la
     * DRH, sinon le premier trouvé. */
    public static function gestionnairePour(Agent $agent): ?Agent
    {
        $base = Agent::whereHas('user.roles', fn ($q) => $q->where('code', 'ROLE_GESTIONNAIRE_RH'));

        return (clone $base)->where('structure_id', $agent->structure_id)->first() ??
            ((clone $base)->whereHas('structure', fn ($q) => $q->where('code', 'DRH'))->first() ??
                $base->first());
    }

    /** DRH destinataire d'une déclaration vérifiée (structure DRH en priorité). Résolution propre à
     * l'état civil : ne réutilise pas le service des permissions. */
    public static function drh(): ?Agent
    {
        $base = Agent::whereHas('user.roles', fn ($q) => $q->where('code', 'ROLE_DRH'));

        return (clone $base)->whereHas('structure', fn ($q) => $q->where('code', 'DRH'))->first() ??
            $base->first();
    }

    /** Alerte le gestionnaire RH de l'agent qu'une déclaration attend son contrôle. */
    protected static function avertirGestionnaire(
        string $type,
        DeclarationNaissance|DeclarationDeces $declaration,
        Agent $agent,
    ): void {
        $libelle = $type === 'NAISSANCE' ? 'naissance' : 'décès';
        $gestionnaire = self::gestionnairePour($agent);
        if ($gestionnaire) {
            self::notifier(
                $gestionnaire->id,
                "Déclaration de {$libelle} à vérifier",
                "Nouvelle déclaration {$declaration->code_dossier} soumise par {$agent->fullName()}.",
                'ATTENTE_CONTROLE',
                $declaration->code_dossier,
            );
        }
        self::notifier(
            $agent->id,
            'Déclaration transmise',
            "Votre déclaration {$declaration->code_dossier} a été transmise au Gestionnaire RH pour vérification.",
            'INFO',
            $declaration->code_dossier,
        );
    }

    /** Déclaration déposée par un sous-directeur ou un directeur : elle n'est pas vérifiée par le
     * gestionnaire RH (le visa serait le sien), elle part au DRH. */
    protected static function avertirDrh(
        string $type,
        DeclarationNaissance|DeclarationDeces $declaration,
        Agent $agent,
    ): void {
        $libelle = $type === 'NAISSANCE' ? 'naissance' : 'décès';
        $drh = self::drh();
        if ($drh) {
            self::notifier(
                $drh->id,
                "Déclaration de {$libelle} d’un responsable à valider",
                "{$agent->fullName()} a déposé la déclaration {$declaration->code_dossier} : décision DRH requise.",
                'ATTENTE_DRH',
                $declaration->code_dossier,
            );
        }
        self::notifier(
            $agent->id,
            'Déclaration transmise',
            "Votre déclaration {$declaration->code_dossier} a été transmise directement au DRH.",
            'INFO',
            $declaration->code_dossier,
        );
    }

    /** Inscrit une étape dans l'historique de la déclaration et dans le journal d'audit. */
    public static function tracer(
        string $type,
        DeclarationNaissance|DeclarationDeces $declaration,
        ?Agent $acteur,
        ?string $role,
        string $action,
        ?string $ancien,
        ?string $nouveau,
        ?string $commentaire = null,
    ): void {
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
        JournalAudit::noter(
            JournalAudit::DOSSIER,
            $action,
            $acteur?->user,
            trim(
                ($role ? "[{$role}] " : '').
                    ($ancien || $nouveau ? "{$ancien} → {$nouveau}" : '').
                    ($commentaire ? " — {$commentaire}" : ''),
            ),
            $declaration->code_dossier,
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
