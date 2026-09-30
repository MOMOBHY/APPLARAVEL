<?php

namespace Tests\Feature;

use App\Models\JournalAudit;
use App\Models\TypePermission;
use App\Models\User;
use App\Services\PermissionWorkflowService;
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
        $this->postJson('/api/login', [
            'matricule' => 'AGT001',
            'password' => 'faux',
        ])->assertUnauthorized();
        $this->postJson('/api/login', [
            'matricule' => 'INCONNU',
            'password' => 'faux',
        ])->assertUnauthorized();
        $token = $this->postJson('/api/login', [
            'matricule' => 'AGT001',
            'password' => 'test123',
        ])->json('token');
        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseHas('journal_audit', [
            'matricule' => 'AGT001',
            'action' => 'CONNEXION',
            'reussi' => true,
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'matricule' => 'AGT001',
            'action' => 'DECONNEXION',
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'matricule' => 'AGT001',
            'action' => 'CONNEXION_REFUSEE',
            'reussi' => false,
        ]);
        $this->assertDatabaseHas('journal_audit', [
            'matricule' => 'INCONNU',
            'action' => 'CONNEXION_REFUSEE',
            'user_id' => null,
        ]);
    }

    public function test_actions_sur_dossier_sont_journalisees_et_visibles_par_l_admin_seulement(): void
    {
        $agent = User::where('matricule', 'AGT001')->firstOrFail();
        $this->actingAs($agent, 'sanctum')->postJson('/api/journal')->assertNotFound();
        $this->actingAs($agent, 'sanctum')->getJson('/api/admin/journal')->assertForbidden();

        PermissionWorkflowService::soumettre($agent->agent, [
            'type_permission_id' => TypePermission::firstOrFail()->id,
            'date_debut' => '2026-10-01',
            'date_fin' => '2026-10-01',
            'motif' => 'Test',
        ]);
        $this->assertTrue(
            JournalAudit::where('categorie', 'DOSSIER')->where('matricule', 'AGT001')->exists(),
        );

        $admin = User::where('matricule', 'ADM001')->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/journal?categorie=DOSSIER')
            ->assertOk()
            ->assertJsonPath('lignes.0.matricule', 'AGT001')
            ->assertJsonStructure(['resume' => ['connexions_aujourdhui']]);
        $this->actingAs($admin, 'sanctum')
            ->get('/api/admin/journal/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_creation_de_compte_par_l_admin_est_journalisee(): void
    {
        $admin = User::where('matricule', 'ADM001')->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'matricule' => 'NEW01',
                'nom' => 'Zadi',
                'prenom' => 'Paul',
                'password' => 'motdepasse',
                'role' => 'ROLE_AGENT',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('journal_audit', [
            'matricule' => 'ADM001',
            'action' => 'COMPTE_CREE',
            'reference' => 'NEW01',
        ]);
    }

    /** L'écran demande une page courte (sans défilement) ; 40 lignes par défaut, bornes 5 à 100. */
    public function test_le_journal_accepte_un_nombre_de_lignes_par_page(): void
    {
        for ($i = 0; $i < 45; $i++) {
            JournalAudit::noter(JournalAudit::DOSSIER, 'TEST', null, "Événement {$i}");
        }
        $admin = User::where('matricule', 'ADM001')->firstOrFail();

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/journal')
            ->assertOk()->assertJsonCount(40, 'lignes');
        $reponse = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/journal?per_page=8')->assertOk();
        $reponse->assertJsonCount(8, 'lignes');
        $this->assertSame((int) ceil($reponse->json('total') / 8), $reponse->json('pages'));
        // Valeurs hors bornes ramenées dans l'intervalle.
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/journal?per_page=1')
            ->assertOk()->assertJsonCount(5, 'lignes');
    }
}
