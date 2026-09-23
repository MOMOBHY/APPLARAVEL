<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\DeclarationHistorique;
use App\Models\PieceJointe;
use App\Models\User;
use App\Services\EtatCivilService;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EtatCivilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
        Storage::fake('local');
    }

    private function user(string $matricule): User
    {
        return User::where('matricule', $matricule)->firstOrFail();
    }

    public function test_naissance_exige_extrait_scanne(): void
    {
        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/naissances', [
                'nom_enfant' => 'KOUASSI',
                'prenom_enfant' => 'Yann',
                'date_naissance_enfant' => '2026-08-20',
                'lieu_naissance_enfant' => 'Abidjan',
            ])->assertStatus(422);
    }

    public function test_naissance_circuit_service_puis_drh(): void
    {
        $creation = $this->actingAs($this->user('AGT001'), 'sanctum')->postJson('/api/naissances', [
            'nom_enfant' => 'KOUASSI',
            'prenom_enfant' => 'Yann',
            'date_naissance_enfant' => '2026-08-20',
            'lieu_naissance_enfant' => 'Abidjan',
            'extrait' => UploadedFile::fake()->create('extrait.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');

        $this->assertEquals(EtatCivilService::EN_ATTENTE_SERVICE, $creation['statut']);
        $this->assertDatabaseHas('pieces_jointes', ['dossier_type' => 'naissance', 'reference_dossier' => $creation['code_dossier']]);

        $id = $creation['id'];

        // Ni la DRH ni le Gestionnaire RH ne contrôlent : service dédié uniquement.
        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson("/api/naissances/{$id}/controler", ['decision' => 'conforme'])
            ->assertForbidden();
        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/naissances/{$id}/controler", ['decision' => 'conforme'])
            ->assertForbidden();

        $this->actingAs($this->user('SVC001'), 'sanctum')
            ->postJson("/api/naissances/{$id}/controler", ['decision' => 'conforme'])
            ->assertOk()->assertJsonPath('data.statut', EtatCivilService::EN_ATTENTE_RH);

        // Le service ne fait pas la validation finale.
        $this->actingAs($this->user('SVC001'), 'sanctum')
            ->postJson("/api/naissances/{$id}/valider", ['valide' => true])
            ->assertForbidden();

        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson("/api/naissances/{$id}/valider", ['valide' => true])
            ->assertOk()->assertJsonPath('data.statut', 'VALIDEE');

        $actions = DeclarationHistorique::where('type_dossier', 'NAISSANCE')
            ->where('dossier_id', $id)->pluck('action')->all();
        foreach (['SOUMISSION', 'CONTROLE_CONFORME', 'VALIDATION'] as $attendue) {
            $this->assertContains($attendue, $actions);
        }
    }

    public function test_deces_retour_correction_puis_rejet_motive(): void
    {
        $creation = $this->actingAs($this->user('AGT001'), 'sanctum')->postJson('/api/deces', [
            'nom_defunt' => 'KOUASSI',
            'prenom_defunt' => 'Papa',
            'lien_parente' => 'ascendant',
            'date_deces' => '2026-08-10',
            'lieu_deces' => 'Bouaké',
            'certificat' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');
        $id = $creation['id'];

        $this->actingAs($this->user('SVC001'), 'sanctum')
            ->postJson("/api/deces/{$id}/controler", ['decision' => 'retourner', 'motif' => 'Certificat illisible'])
            ->assertOk()->assertJsonPath('data.statut', 'RETOUR_CORRECTION');

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson("/api/deces/{$id}/corriger", [
                'lieu_deces' => 'Abidjan',
                'certificat' => UploadedFile::fake()->create('cert2.pdf', 100, 'application/pdf'),
            ])->assertOk()->assertJsonPath('data.statut', EtatCivilService::EN_ATTENTE_SERVICE);

        $this->actingAs($this->user('SVC001'), 'sanctum')
            ->postJson("/api/deces/{$id}/controler", ['decision' => 'conforme'])
            ->assertOk();

        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson("/api/deces/{$id}/valider", ['valide' => false, 'motif' => 'Lien non établi'])
            ->assertOk()->assertJsonPath('data.statut', 'REJETEE');

        $this->assertDatabaseHas('declarations_deces', ['id' => $id, 'motif_rejet' => 'Lien non établi']);
    }

    public function test_deces_exige_certificat_et_lien_restreint(): void
    {
        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/deces', [
                'nom_defunt' => 'KOUASSI',
                'prenom_defunt' => 'Papa',
                'lien_parente' => 'oncle',
                'date_deces' => '2026-08-10',
                'lieu_deces' => 'Bouaké',
                'certificat' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
            ])->assertStatus(422);
    }

    public function test_piece_jointe_reservee_aux_habilites(): void
    {
        $creation = $this->actingAs($this->user('AGT001'), 'sanctum')->postJson('/api/naissances', [
            'nom_enfant' => 'KOUASSI',
            'prenom_enfant' => 'Yann',
            'date_naissance_enfant' => '2026-08-20',
            'lieu_naissance_enfant' => 'Abidjan',
            'extrait' => UploadedFile::fake()->create('extrait.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('data');

        $piece = PieceJointe::where('reference_dossier', $creation['code_dossier'])->firstOrFail();

        // Un tiers sans droits ne télécharge pas.
        $autre = Agent::create(['matricule' => 'TIERS01', 'civilite' => 'M.', 'nom' => 'TIERS', 'prenom' => 'Autre']);
        $autreUser = User::create(['name' => 'Tiers', 'email' => 'tiers01@x.ci', 'matricule' => 'TIERS01', 'password' => 'x', 'agent_id' => $autre->id]);
        $this->actingAs($autreUser, 'sanctum')->get("/api/pieces/{$piece->id}")->assertForbidden();

        // Déclarant, service et DRH oui.
        $this->actingAs($this->user('AGT001'), 'sanctum')->get("/api/pieces/{$piece->id}")->assertOk();
        $this->actingAs($this->user('SVC001'), 'sanctum')->get("/api/pieces/{$piece->id}")->assertOk();
        $this->actingAs($this->user('DRH001'), 'sanctum')->get("/api/pieces/{$piece->id}")->assertOk();
    }
}
