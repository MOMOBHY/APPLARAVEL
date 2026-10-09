<?php

namespace Tests\Feature;

use Tests\TestCase;

class NavigationOngletsTest extends TestCase
{
    public function test_les_espaces_connectes_utilisent_un_etat_actif_unique_et_accessible(): void
    {
        foreach (['agent.html', 'responsable.html', 'drh.html', 'admin.html', 'notes.html'] as $vue) {
            $html = file_get_contents(base_path('public/gfp/views/'.$vue));
            $this->assertIsString($html);
            $this->assertStringContainsString(
                'activerOngletGfp(',
                $html,
                "La vue {$vue} doit actualiser l'onglet sélectionné.",
            );
        }

        $shell = file_get_contents(base_path('public/gfp/js/shell.js'));
        $this->assertIsString($shell);
        $this->assertStringContainsString("bouton.classList.toggle('nav-tab-active', actif)", $shell);
        $this->assertStringContainsString("bouton.setAttribute('aria-current', 'page')", $shell);
        $this->assertStringContainsString("bouton.removeAttribute('aria-current')", $shell);
    }
}
