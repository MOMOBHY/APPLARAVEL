<?php

namespace Tests\Feature;

use Tests\TestCase;

class OngletsPermissionsEtatCivilTest extends TestCase
{
    public function test_les_sections_et_leurs_onglets_restent_presents_dans_la_navigation(): void
    {
        $cas = [
            'agent.html' => [
                ['nav-permissions', 'nav-naissances', 'nav-deces'],
                ['tab-permissions', 'tab-naissances', 'tab-deces', 'function soumettrePermission'],
            ],
            'responsable.html' => [
                ['tabButton', 'nav-actes', 'nav-permissions', 'nav-naissances', 'nav-deces'],
                ['tab-controle', 'tab-actes', 'tab-permissions', 'tab-naissances', 'tab-deces', 'function controlerDeclaration'],
            ],
            'drh.html' => [
                ['nav-permissions', 'nav-etatcivil'],
                ['tab-permissions', 'tab-etatcivil', 'function decide('],
            ],
        ];

        foreach ($cas as $fichier => [$navigationIds, $sections]) {
            $html = file_get_contents(base_path('public/gfp/views/'.$fichier));
            $this->assertIsString($html);

            foreach ($navigationIds as $id) {
                $this->assertStringContainsString('id="'.$id.'"', $html);
            }
            foreach ($sections as $section) {
                $this->assertStringContainsString($section, $html);
            }
        }

        $theme = file_get_contents(base_path('public/gfp/assets/theme.css'));
        $this->assertIsString($theme);
        foreach (['nav-permissions', 'nav-etatcivil', 'nav-actes', 'nav-naissances', 'nav-deces', 'tabButton'] as $id) {
            $this->assertStringNotContainsString('#'.$id, $theme);
        }
        $this->assertStringContainsString('id="nav-permissions"', $this->html('agent.html'));
        $this->assertStringContainsString('id="nav-etatcivil"', $this->html('drh.html'));
    }

    private function html(string $fichier): string
    {
        $html = file_get_contents(base_path('public/gfp/views/'.$fichier));
        $this->assertIsString($html);

        return $html;
    }
}
