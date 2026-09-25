<?php

namespace Tests\Feature;

use App\Models\Structure;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestionUtilisateursTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
        $this->admin = User::where('matricule', 'ADM001')->firstOrFail();
    }

    public function test_le_role_reel_est_expose_et_conserve_a_la_modification(): void
    {
        $users = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/users')->json('users'))->keyBy('matricule');
        $this->assertSame('ROLE_DIRECTEUR', $users['DIR001']['role_code']);
        $this->assertSame('ROLE_SECRETAIRE', $users['SEC001']['role_code']);

        // Changer la structure d'un directeur ne touche pas à son rôle.
        $structure = Structure::where('code', 'DRH')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users/update', ['matricule' => 'DIR001', 'structure_id' => $structure->id])->assertOk();

        $dir = User::where('matricule', 'DIR001')->firstOrFail();
        $this->assertSame(['ROLE_DIRECTEUR'], $dir->roles->pluck('code')->all());
        $this->assertSame($structure->id, $dir->agent->structure_id);

        // Attribution d'un rôle réel précis (et non plus un regroupement).
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users/update', ['matricule' => 'AGT001', 'role' => 'ROLE_SOUS_DIRECTEUR'])->assertOk();
        $this->assertSame(['ROLE_SOUS_DIRECTEUR'], User::where('matricule', 'AGT001')->firstOrFail()->roles->pluck('code')->all());
    }

    public function test_compte_suspendu_ne_peut_plus_se_connecter_et_perd_ses_jetons(): void
    {
        $token = $this->postJson('/api/login', ['matricule' => 'AGT001', 'password' => 'test123'])->json('token');

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users/update', ['matricule' => 'AGT001', 'actif' => false])->assertOk();

        $this->postJson('/api/login', ['matricule' => 'AGT001', 'password' => 'test123'])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('journal_audit', ['matricule' => 'AGT001', 'action' => 'CONNEXION_REFUSEE', 'description' => 'Compte suspendu']);
        $statut = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/users')->json('users'))->firstWhere('matricule', 'AGT001')['statut'];
        $this->assertSame('SUSPENDU', $statut);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users/update', ['matricule' => 'AGT001', 'actif' => true])->assertOk();
        $this->postJson('/api/login', ['matricule' => 'AGT001', 'password' => 'test123'])->assertOk();
    }

    public function test_l_administrateur_ne_peut_pas_se_suspendre_ni_se_degrader(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users/update', ['matricule' => 'ADM001', 'actif' => false])->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users/update', ['matricule' => 'ADM001', 'role' => 'ROLE_AGENT'])->assertUnprocessable();
        $this->assertTrue($this->admin->fresh()->actif);
    }

    public function test_creation_avec_structure_et_telephone_reels(): void
    {
        $structure = Structure::where('code', 'DRH')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users', [
            'matricule' => 'reel-01', 'civilite' => 'Mme', 'nom' => 'Yao', 'prenom' => 'Awa', 'role' => 'ROLE_SECRETAIRE',
            'password' => 'motdepasse', 'structure_id' => $structure->id, 'telephone' => '+225 07 00 00 00 00',
        ])->assertCreated();

        $agent = User::where('matricule', 'REEL-01')->firstOrFail()->agent;
        $this->assertSame($structure->id, $agent->structure_id);
        $this->assertSame('+225 07 00 00 00 00', $agent->telephone);
        $this->assertSame('Mme', $agent->civilite);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/users', [
            'matricule' => 'court', 'nom' => 'A', 'prenom' => 'B', 'role' => 'ROLE_AGENT', 'password' => '123',
        ])->assertUnprocessable();
    }
}
