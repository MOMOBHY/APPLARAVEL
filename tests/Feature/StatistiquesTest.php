<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\DemandePermission;
use App\Models\TypePermission;
use App\Models\User;
use App\Services\PermissionWorkflowService;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatistiquesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
    }

    private function demande(): DemandePermission
    {
        return PermissionWorkflowService::soumettre(Agent::where('matricule', 'AGT001')->firstOrFail(), [
            'type_permission_id' => TypePermission::firstOrFail()->id,
            'date_debut' => '2026-10-01',
            'date_fin' => '2026-10-05',
            'motif' => 'Motif de test',
        ]);
    }

    public function test_tableau_de_bord_reserve_au_drh_et_a_l_administrateur(): void
    {
        $this->actingAs(User::where('matricule', 'AGT001')->firstOrFail(), 'sanctum')
            ->getJson('/api/statistiques')->assertForbidden();
        $this->actingAs(User::where('matricule', 'RH001')->firstOrFail(), 'sanctum')
            ->getJson('/api/statistiques')->assertForbidden();

        $this->actingAs(User::where('matricule', 'ADM001')->firstOrFail(), 'sanctum')
            ->getJson('/api/statistiques')->assertOk();
    }

    public function test_indicateurs_par_statut_et_delai(): void
    {
        $rh = Agent::where('matricule', 'RH001')->firstOrFail();
        $drh = Agent::where('matricule', 'DRH001')->firstOrFail();

        $validee = PermissionWorkflowService::verifierRh($this->demande(), $rh, 'conforme');
        PermissionWorkflowService::trancherDrh($validee, $drh, true);
        $rejetee = PermissionWorkflowService::verifierRh($this->demande(), $rh, 'conforme');
        PermissionWorkflowService::trancherDrh($rejetee, $drh, false, 'Service en sous-effectif');
        $this->demande();

        $this->actingAs(User::where('matricule', 'DRH001')->firstOrFail(), 'sanctum')
            ->getJson('/api/statistiques')->assertOk()
            ->assertJsonPath('indicateurs.total', 3)
            ->assertJsonPath('indicateurs.en_cours', 1)
            ->assertJsonPath('indicateurs.taux_validation', 50)
            ->assertJsonPath('par_statut.validee', 1)
            ->assertJsonPath('par_statut.rejetee', 1)
            ->assertJsonPath('par_structure.0.structure', 'Service des Études')
            ->assertJsonPath('par_mois.11.total', 3)
            ->assertJsonPath('delai_par_nature.0.dossiers_clos', 2);
    }
}
