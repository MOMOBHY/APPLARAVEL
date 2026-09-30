<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Garde-fou « structure non affectée » des deux côtés (serveur, interface, bouton d'affectation de
 * l'admin), vérifié sur les fichiers servis. */
class VerrouStructureInterfaceTest extends TestCase
{
    private function vue(string $nom): string
    {
        $contenu = file_get_contents(base_path("public/gfp/{$nom}"));
        $this->assertIsString($contenu);

        return $contenu;
    }

    // Côté agent : les trois onglets de dépôt sont verrouillables

    public function test_les_trois_onglets_de_depot_sont_marques_comme_verrouillables(): void
    {
        $agent = $this->vue('views/agent.html');

        foreach (['permissions', 'naissances', 'deces'] as $onglet) {
            $this->assertMatchesRegularExpression(
                '/id="nav-' . $onglet . '"[^>]*data-bloque="1"/',
                $agent,
                "L'onglet « {$onglet} » doit porter data-bloque pour être grisable.",
            );
        }
    }

    public function test_le_verrou_est_decide_par_la_presence_de_structure(): void
    {
        $agent = $this->vue('views/agent.html');

        $this->assertStringContainsString('function structureAffectee()', $agent);
        $this->assertStringContainsString('user.structure_id', $agent);
        $this->assertStringContainsString('appliquerGardeStructure', $agent);
        // La bannière doit exister et rester masquée tant que tout va bien.
        $this->assertStringContainsString('id="gardeStructure"', $agent);
    }

    public function test_le_verrou_interdit_ouvrir_un_formulaire(): void
    {
        $agent = $this->vue('views/agent.html');

        // showForm et showTab doivent tous deux refuser l'ouverture.
        $this->assertSame(
            2,
            preg_match_all('/if \(!structureAffectee\(\)\)/', $agent),
            'Le verrou doit protéger showForm() et showTab().',
        );
    }

    public function test_la_session_est_relue_a_vue_pour_voir_une_affectation_posterieure(): void
    {
        // Sans relecture de /api/me, l'affectation faite par l'administrateur pendant
        // que l'agent est connecté resterait invisible jusqu'à la reconnexion.
        $this->assertStringContainsString('async refreshSession()', $this->vue('js/api.js'));
        $this->assertStringContainsString('API.refreshSession()', $agent = $this->vue('views/agent.html'));
    }

    // Côté administrateur : un acte d'affectation explicite

    public function test_l_administrateur_dispose_d_un_bouton_d_affectation(): void
    {
        $admin = $this->vue('views/admin.html');

        $this->assertStringContainsString('Affecter la structure', $admin);
        $this->assertStringContainsString('function affecterStructure(', $admin);
    }

    public function test_l_enregistrement_simple_ne_touche_plus_a_la_structure(): void
    {
        $admin = $this->vue('views/admin.html');

        // saveUserChanges ne doit plus envoyer structure_id : l'affectation est un
        // acte à part, qui valide et notifie.
        $bloc = substr(
            $admin,
            strpos($admin, 'async function saveUserChanges'),
            strpos($admin, 'async function affecterStructure') - strpos($admin, 'async function saveUserChanges'),
        );
        $this->assertStringNotContainsString('structure_id', $bloc);
    }

    // Le miroir servi doit être identique aux sources

    public function test_le_miroir_servi_est_identique_aux_sources(): void
    {
        $racine = dirname(base_path());
        $fichiers = [
            'views/agent.html',
            'views/admin.html',
            'views/responsable.html',
            'views/notes.html',
            'js/api.js',
            'js/shell.js',
        ];

        foreach ($fichiers as $fichier) {
            $this->assertFileEquals(
                $racine.'/frontend/'.$fichier,
                base_path('public/gfp/'.$fichier),
                "gfp-laravel/public/gfp/{$fichier} n'est plus aligné sur frontend/{$fichier}.",
            );
        }
    }
}
