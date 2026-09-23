<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\TypePermission;
use App\Models\User;
use App\Services\PermissionWorkflowService;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
    }

    public function test_directeur_central_ne_peut_pas_utiliser_le_profil_directeur_de_cabinet(): void
    {
        $this->postJson('/api/login', [
            'matricule' => 'DIR001',
            'password' => 'test123',
            'role' => 'DIRCAB',
        ])->assertForbidden();

        $this->postJson('/api/login', [
            'matricule' => 'CAB001',
            'password' => 'test123',
            'role' => 'DIRCAB',
        ])->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_inscription_publique_ne_peut_pas_attribuer_un_role_sensible(): void
    {
        $this->postJson('/api/register', [
            'civilite' => 'Mme',
            'nom' => 'Kouassi',
            'prenom' => 'Aminata',
            'matricule' => 'TEST-RH-001',
            'password' => 'motdepasse',
            'role' => 'ROLE_DRH',
        ])->assertUnprocessable()->assertJsonPath('status', 'error');

        $this->assertDatabaseMissing('users', ['matricule' => 'TEST-RH-001']);
    }

    public function test_administrateur_peut_attribuer_un_role_gestionnaire_sans_role_sous_directeur(): void
    {
        $admin = User::where('matricule', 'ADM001')->firstOrFail();

        $this->actingAs($admin, 'sanctum')->postJson('/api/users', [
            'civilite' => 'Mme',
            'nom' => 'Kouassi',
            'prenom' => 'Aminata',
            'matricule' => 'TEST-RH-001',
            'password' => 'motdepasse',
            'role' => 'ROLE_CHEF_SERVICE',
        ])->assertCreated()->assertJsonPath('status', 'success');

        $roles = User::where('matricule', 'TEST-RH-001')->firstOrFail()->roles->pluck('code');

        $this->assertTrue($roles->contains('ROLE_GESTIONNAIRE_RH'));
        $this->assertFalse($roles->contains('ROLE_SOUS_DIRECTEUR'));
    }

    public function test_agent_ne_voit_pas_les_demandes_d_un_autre_agent(): void
    {
        $demande = PermissionWorkflowService::soumettre(User::where('matricule', 'SVC001')->firstOrFail()->agent, [
            'type_permission_id' => TypePermission::firstOrFail()->id,
            'date_debut' => '2026-10-01',
            'date_fin' => '2026-10-01',
            'motif' => 'Demande confidentielle',
        ]);

        $agent = User::where('matricule', 'AGT001')->firstOrFail();

        $this->actingAs($agent, 'sanctum')->getJson('/api/requests')
            ->assertOk()
            ->assertJsonMissing(['id' => $demande->code_dossier]);
    }

    public function test_agent_ne_peut_pas_consulter_ni_marquer_les_notifications_d_un_autre_agent(): void
    {
        $destinataire = User::where('matricule', 'SVC001')->firstOrFail();
        $notification = Notification::create([
            'agent_id' => $destinataire->agent_id,
            'titre' => 'Notification privée',
            'message' => 'Cette notification ne doit pas être exposée.',
            'type' => 'INFO',
            'reference_dossier' => 'PRIVE-001',
        ]);
        $agent = User::where('matricule', 'AGT001')->firstOrFail();

        $this->actingAs($agent, 'sanctum')->getJson('/api/notifications?agent_id='.$destinataire->agent_id)
            ->assertOk()
            ->assertJsonMissing(['id_notification' => $notification->id]);

        $this->actingAs($agent, 'sanctum')->postJson('/api/notifications/read', [
            'agent_id' => $destinataire->agent_id,
        ])->assertOk();

        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'est_lu' => false]);
    }
}
