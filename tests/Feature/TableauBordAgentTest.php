<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Tableau de bord de l'agent : justificatif de chaque dossier (« Voir ») et libellés explicites des
 * trois natures, vérifiés sur le HTML servi. */
class TableauBordAgentTest extends TestCase
{
    private function html(): string
    {
        $html = file_get_contents(base_path('public/gfp/views/agent.html'));
        $this->assertIsString($html);

        return $html;
    }

    public function test_le_tableau_de_bord_montre_le_justificatif(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('<th class="px-4 py-3">Justificatif</th>', $html);
        $this->assertStringContainsString('API.ouvrirPiece(${r.piece_id})', $html);
    }

    public function test_les_compteurs_et_types_sont_explicites(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Demandes de permission', $html);
        $this->assertStringContainsString('Déclarations de naissance', $html);
        $this->assertStringContainsString('Déclarations de décès', $html);
        $this->assertStringContainsString('Demande de permission', $html);
        $this->assertStringContainsString('Déclaration de naissance', $html);
        $this->assertStringContainsString('Déclaration de décès', $html);
    }

    public function test_le_tableau_de_bord_trie_du_plus_recent(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('triPlusRecent(requests.filter(', $html);
    }

    public function test_le_statut_reel_est_unique_et_le_dashboard_se_rafraichit(): void
    {
        $html = $this->html();
        $api = file_get_contents(base_path('public/gfp/js/api.js'));
        $this->assertIsString($api);

        // Une seule définition du statut réel, utilisée par le dashboard.
        $this->assertStringContainsString('function statutReel(r)', $api);
        $this->assertStringContainsString('statutReel(r)', $html);
        // Rafraîchissement automatique : les décisions des autres arrivent sans recharger.
        $this->assertStringContainsString('setInterval(', $html);
    }
}
