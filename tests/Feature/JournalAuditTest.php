<?php

namespace Tests\Feature;

use App\Models\JournalAudit;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
    }

    public function test_connexions_echecs_et_deconnexion_sont_journalises(): void
    {
        $this->postJson('/api/login', ['matricule' => 'AGT001', 'password' => 'faux'])->assertUnauthorized();
        $this->postJson('/api/login', ['matricule' => 'INCONNU', 'password' => 'faux'])->assertUnauthorized();
        $token = $this->postJson('/api/login', ['matricule' => 'AGT001', 'password' => 'test123'])->json('token');
        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseHas('journal_audit', ['matricule' => 'AGT001', 'action' => 'CONNEXION', 'reussi' => true]);
        $this->assertDatabaseHas('journal_audit', ['matricule' => 'AGT001', 'action' => 'DECONNEXION']);
        $this->assertDatabaseHas('journal_audit', ['matricule' => 'AGT001', 'action' => 'CONNEXION_REFUSEE', 'reussi' => false]);
        $this->assertDatabaseHas('journal_audit', ['matricule' => 'INCONNU', 'action' => 'CONNEXION_REFUSEE', 'user_id' => null]);
    }

    public function test_actions_sur_dossier_sont_journalisees_et_visibles_par_l_admin_seulement(): void
    {
        $agent = User::where('matricule', 'AGT001')->firstOrFail();
        $this->actingAs($agent, 'sanctum')->postJson('/api/journal')->assertNotFound();
        $this->actingAs($agent, 'sanctum')->getJson('/api/admin/journal')->assertForbidden();

        \App\Services\PermissionWorkflowService::soumettre($agent->agent, [
            'type_permission_id' => \App\Models\TypePermission::firstOrFail()->id,
            'date_debut' => '2026-10-01', 'date_fin' => '2026-10-01', 'motif' => 'Test',
        ]);
        $this->assertTrue(JournalAudit::where('categorie', 'DOSSIER')->where('matricule', 'AGT001')->exists());

        $admin = User::where('matricule', 'ADM001')->firstOrFail();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/journal?categorie=DOSSIER')
            ->assertOk()->assertJsonPath('lignes.0.matricule', 'AGT001')->assertJsonStructure(['resume' => ['connexions_aujourdhui']]);
        $this->actingAs($admin, 'sanctum')->get('/api/admin/journal/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_creation_de_compte_par_l_admin_est_journalisee(): void
    {
        $admin = User::where('matricule', 'ADM001')->firstOrFail();
        $this->actingAs($admin, 'sanctum')->postJson('/api/users', [
            'matricule' => 'NEW01', 'nom' => 'Zadi', 'prenom' => 'Paul', 'password' => 'motdepasse', 'role' => 'ROLE_AGENT',
        ])->assertCreated();

        $this->assertDatabaseHas('journal_audit', ['matricule' => 'ADM001', 'action' => 'COMPTE_CREE', 'reference' => 'NEW01']);
    }
}
