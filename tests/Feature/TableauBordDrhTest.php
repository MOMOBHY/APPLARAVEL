<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Tableau de bord du DRH, centré sur les dossiers à trancher ; « Tableau de Bord » actif à l'arrivée.
 * Vérifié sur le HTML servi. */
class TableauBordDrhTest extends TestCase
{
    private function html(): string
    {
        $html = file_get_contents(base_path('public/gfp/views/drh.html'));
        $this->assertIsString($html);

        return $html;
    }

    public function test_le_tableau_de_bord_est_le_premier_onglet_et_il_est_vert(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('id="nav-dashboard"', $html);
        $this->assertStringContainsString('id="tab-dashboard"', $html);
        // Le vert est sur le tableau de bord, pas sur les permissions.
        $this->assertMatchesRegularExpression(
            '/id="nav-dashboard"\s*\n?\s*class="[^"]*bg-emerald-700[^"]*"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/id="nav-permissions"\s*\n?\s*class="[^"]*text-slate-600[^"]*"/',
            $html,
        );
        // L'onglet permissions est masqué au démarrage.
        $this->assertStringContainsString('id="tab-permissions" class="hidden', $html);
    }

    public function test_les_compteurs_suivent_les_taches_du_drh(): void
    {
        $html = $this->html();

        foreach (['countPermDrh', 'countEcDrh', 'countTranchesDrh', 'countTotalDrh'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
    }

    public function test_le_tableau_recapitule_les_dossiers_a_trancher_avec_valider_retourner(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('id="dashDrhTable"', $html);
        $this->assertStringContainsString('id="dashDrhPagination"', $html);
        $this->assertStringContainsString('function renderDashboard()', $html);
        // Décision finale directement depuis le tableau de bord : valider ou retourner.
        $this->assertStringContainsString('decide(\'${codeDossier}\', \'VALIDEE\')', $html);
        $this->assertStringContainsString('decide(\'${codeDossier}\', \'RETOUR_CORRECTION\')', $html);
        $this->assertStringNotContainsString('decide(\'${codeDossier}\', \'REJETEE\')', $html);
    }

    /** Un dossier tranché reste sur le tableau de bord avec son statut final (plus de boutons de
     * décision, un bouton Suivre). */
    public function test_les_dossiers_tranches_restent_sur_le_tableau_de_bord(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('function estATrancherDrh(r)', $html);
        $this->assertStringContainsString('id="dashDrhStatut"', $html);
        $this->assertStringContainsString('Tous les dossiers suivis', $html);
        $this->assertStringContainsString('onclick="ouvrirSuivi(\'${codeDossier}\')"', $html);
    }

    public function test_le_dashboard_est_actif_a_l_arrivee_et_rafraichi_avec_le_reste(): void
    {
        $html = $this->html();

        $this->assertStringContainsString("showTab('dashboard')", $html);
        // showTab connaît l'onglet dashboard.
        $this->assertStringContainsString("'dashboard', 'permissions', 'etatcivil'", $html);
        // renderAll redessine le dashboard avec les autres onglets.
        $this->assertMatchesRegularExpression(
            '/function renderAll\(\)\s*\{[^}]*renderDashboard\(\)/s',
            $html,
        );
    }

    public function test_les_onglets_metier_sont_conserves_sauf_structures_et_historique(): void
    {
        $html = $this->html();

        foreach (['permissions', 'etatcivil', 'statistiques', 'notes'] as $onglet) {
            $this->assertStringContainsString('id="nav-'.$onglet.'"', $html);
            $this->assertStringContainsString('id="tab-'.$onglet.'"', $html);
        }
        // L'onglet « Structures du ministère » est supprimé.
        $this->assertStringNotContainsString('id="nav-structures"', $html);
        $this->assertStringNotContainsString('id="tab-structures"', $html);
        // L'onglet « Historique des Décisions » est supprimé : l'historique des
        // permissions vit dans la colonne gauche de l'onglet permissions.
        $this->assertStringNotContainsString('id="nav-historique"', $html);
        $this->assertStringNotContainsString('id="tab-historique"', $html);
        $this->assertStringNotContainsString('function renderHistorique()', $html);
    }

    public function test_l_onglet_permissions_affiche_l_historique_a_gauche(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('id="permHistoContainer"', $html);
        $this->assertStringContainsString('id="drhPermHistoPagination"', $html);
        $this->assertStringContainsString('function renderPermHistorique()', $html);
        $this->assertStringContainsString('Historique des permissions', $html);
        // Affichage 1 par 1 dans les deux colonnes.
        $this->assertStringContainsString('const PAGE_SIZE_PERM = 1;', $html);
        // Filtre sur la durée : 2 jours ou moins, plus de 2 jours.
        $this->assertStringContainsString('id="drhPermDuree"', $html);
        $this->assertStringContainsString('id="drhPermHistoDuree"', $html);
        $this->assertStringContainsString('function filtrerDuree(liste, duree)', $html);
    }

    public function test_le_tableau_montre_le_justificatif_sans_nom_de_fichier(): void
    {
        $api = file_get_contents(base_path('public/gfp/js/api.js'));
        $this->assertIsString($api);

        // Seul le bouton Consulter : jamais le nom haché du fichier stocké.
        $this->assertStringContainsString('>Consulter</button>', $api);
        $this->assertStringNotContainsString('escapeHtml(r.piece)', $api);

        $html = $this->html();
        $this->assertStringContainsString('<th class="px-4 py-3">Justificatif</th>', $html);
        $this->assertStringContainsString('${pieceHtml(r)}', $html);
        $this->assertStringContainsString('Demande de permission', $html);
        $this->assertStringContainsString('Déclaration de naissance', $html);
        $this->assertStringContainsString('Déclaration de décès', $html);
    }

    public function test_le_drh_voit_les_actes_en_attente_de_sa_decision(): void
    {
        $html = $this->html();

        // Sans ce filtre, une naissance ou un décès transmis n'apparaît jamais chez le DRH.
        $this->assertStringContainsString("r.etape === 'EN_ATTENTE_DRH'", $html);
    }

    public function test_le_tableau_de_bord_trie_du_plus_recent(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('triPlusRecent(permsATrancher.concat(', $html);
    }

    public function test_le_drh_a_un_onglet_notes_avec_diffusion_directe(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('id="nav-notes"', $html);
        $this->assertStringContainsString('id="tab-notes"', $html);
        $this->assertStringContainsString('id="formNoteDrh"', $html);
        $this->assertStringContainsString('id="notesDrhContainer"', $html);
        $this->assertStringContainsString('function renderNotesDrh()', $html);
        $this->assertStringContainsString('API.diffuserNoteDirecte(', $html);
        // Aucun circuit de validation pour ses notes.
        $this->assertStringNotContainsString('Circuit de Validation', $html);
    }

    public function test_le_miroir_drh_est_identique_aux_sources(): void
    {
        $this->assertFileEquals(
            dirname(base_path()).'/frontend/views/drh.html',
            base_path('public/gfp/views/drh.html'),
            "gfp-laravel/public/gfp/views/drh.html n'est plus aligné sur frontend/views/drh.html.",
        );
    }
}
