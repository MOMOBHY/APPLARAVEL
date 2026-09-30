<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Chaque type de notification a sa couleur et son libellé. Tout nouveau type émis par les services
 * (self::notifier) doit être ajouté ici et dans shell.js. */
class DesignNotificationsTest extends TestCase
{
    /** Types émis par les services (voir self::notifier dans chaque service). */
    private const TYPES_CONNUS = [
        'VALIDATION',
        'VALIDEE',
        'REJET',
        'REFUS_VALIDATION',
        'RETOUR',
        'RETOUR_CORRECTION',
        'ATTENTE_DRH',
        'ATTENTE_VALIDATION',
        'ATTENTE_VERIF',
        'ATTENTE_CONTROLE',
        'ATTENTE_SAISIE',
        'INFO',
        'STRUCTURE',
        'NOTE_SERVICE',
    ];

    private function shell(): string
    {
        $js = file_get_contents(base_path('public/gfp/js/shell.js'));
        $this->assertIsString($js);

        return $js;
    }

    public function test_chaque_type_a_sa_pastille(): void
    {
        $js = $this->shell();

        foreach (self::TYPES_CONNUS as $type) {
            $this->assertStringContainsString(
                $type.':',
                $js,
                "Le type « {$type} » n'a pas de pastille dans shell.js.",
            );
        }
    }

    public function test_chaque_type_a_son_libelle_lisible(): void
    {
        $js = $this->shell();

        $this->assertStringContainsString('libelleType', $js);
        foreach (self::TYPES_CONNUS as $type) {
            $this->assertMatchesRegularExpression(
                '/libelleType\s*=\s*\{[^}]*'.$type.':/s',
                $js,
                "Le type « {$type} » n'a pas de libellé lisible.",
            );
        }
    }

    /** Cinq familles visuelles : succès, rejet, retour, attente, info. */
    public function test_les_familles_ont_des_couleurs_distinctes(): void
    {
        $js = $this->shell();

        foreach (['bg-emerald-100', 'bg-red-100', 'bg-orange-100', 'bg-amber-100'] as $classe) {
            $this->assertStringContainsString($classe, $js);
        }
        // Rejeté n'est pas vert, validé n'est pas rouge (ancrage en début de ligne).
        $this->assertMatchesRegularExpression("/^    REJET: 'bg-red/m", $js);
        $this->assertMatchesRegularExpression("/^    VALIDATION: 'bg-emerald/m", $js);
    }

    /** La notification toast est une belle carte centrée sur l'écran. */
    public function test_le_toast_est_une_carte_centree(): void
    {
        $js = file_get_contents(base_path('public/gfp/js/api.js'));
        $this->assertIsString($js);

        $this->assertStringContainsString('gfp-notif', $js);
        $this->assertStringContainsString('fixed inset-0', $js);
        $this->assertStringContainsString('rounded-2xl', $js);
        $this->assertStringContainsString('setTimeout(', $js);
    }

    /* POPUPS NATIVES REMPLACÉES PAR DES NOTIFICATIONS DESIGNÉES */

    public function test_les_dialogues_sont_designes_dans_toute_l_app(): void
    {
        $js = file_get_contents(base_path('public/gfp/js/api.js'));
        $this->assertIsString($js);

        $this->assertStringContainsString('function toast(', $js);
        $this->assertStringContainsString('function demanderConfirmation(', $js);
        $this->assertStringContainsString('function demanderMotif(', $js);

        $vues = glob(base_path('public/gfp/views/*.html'));
        $this->assertNotEmpty($vues);
        foreach ($vues as $vue) {
            $html = file_get_contents($vue);
            foreach (['alert(', 'confirm(', 'prompt('] as $natif) {
                $this->assertStringNotContainsString(
                    $natif,
                    $html,
                    basename($vue)." utilise encore {$natif} natif.",
                );
            }
        }
        $this->assertStringNotContainsString('alert(', $js);
        $this->assertStringNotContainsString('confirm(', $js);
        $this->assertStringNotContainsString('prompt(', $js);
    }

    /** La frise s'arrête à la décision finale : plus d'étape fantôme « en attente de notification » après
     * une validation ou un rejet. */
    public function test_la_frise_se_termine_a_la_decision_finale(): void
    {
        $js = file_get_contents(base_path('public/gfp/js/api.js'));
        $this->assertIsString($js);

        $this->assertStringNotContainsString('En attente de la notification', $js);
    }
}
