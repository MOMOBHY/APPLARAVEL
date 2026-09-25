<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Structure;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(GfpSeeder::class);
    }

    private function agent(string $matricule): User
    {
        return User::where('matricule', $matricule)->firstOrFail();
    }

    public function test_seuls_les_roles_habilites_peuvent_partager(): void
    {
        $this->actingAs($this->agent('AGT001'), 'sanctum')->postJson('/api/partages', ['titre' => 'x', 'message' => 'y', 'toutes_structures' => true])->assertForbidden();
        $this->actingAs($this->agent('AGT001'), 'sanctum')->getJson('/api/partages')->assertOk()->assertJsonPath('peut_partager', false);
        $this->actingAs($this->agent('SEC001'), 'sanctum')->getJson('/api/partages')->assertJsonPath('peut_partager', true);
    }

    public function test_partage_cible_visible_seulement_par_les_structures_choisies(): void
    {
        $drh = Structure::where('code', 'DRH')->firstOrFail();

        $this->actingAs($this->agent('DRH001'), 'sanctum')->postJson('/api/partages', [
            'titre' => 'Circulaire RH', 'message' => 'À lire', 'structure_ids' => [$drh->id],
        ])->assertCreated();

        // SVC001 est à la DRH : il voit le partage et reçoit une notification ; AGT001 (Service des Études) non.
        $this->actingAs($this->agent('SVC001'), 'sanctum')->getJson('/api/partages')->assertJsonPath('partages.0.titre', 'Circulaire RH');
        $this->assertTrue(Notification::where('agent_id', $this->agent('SVC001')->agent_id)->where('type', 'PARTAGE')->exists());
        $this->actingAs($this->agent('AGT001'), 'sanctum')->getJson('/api/partages')->assertJsonCount(0, 'partages');
        $this->assertDatabaseHas('journal_audit', ['action' => 'PARTAGE_PUBLIE', 'matricule' => 'DRH001']);
    }

    public function test_partage_a_toutes_les_structures_avec_document_protege(): void
    {
        $id = $this->actingAs($this->agent('SEC001'), 'sanctum')->post('/api/partages', [
            'titre' => 'Note générale', 'toutes_structures' => '1',
            'fichier' => UploadedFile::fake()->create('note.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');

        $reponse = $this->actingAs($this->agent('AGT001'), 'sanctum')->getJson('/api/partages')->assertOk();
        $reponse->assertJsonPath('partages.0.toutes_structures', true)->assertJsonPath('partages.0.supprimable', false);
        $pieceId = $reponse->json('partages.0.piece_id');
        $this->actingAs($this->agent('AGT001'), 'sanctum')->get("/api/pieces/{$pieceId}")->assertOk();

        // Suppression : ni un lecteur, ni un autre émetteur ; l'auteur ou l'admin oui.
        $this->actingAs($this->agent('AGT001'), 'sanctum')->deleteJson("/api/partages/{$id}")->assertForbidden();
        $this->actingAs($this->agent('DIR001'), 'sanctum')->deleteJson("/api/partages/{$id}")->assertForbidden();
        $this->actingAs($this->agent('SEC001'), 'sanctum')->deleteJson("/api/partages/{$id}")->assertOk();
        $this->assertDatabaseCount('partages', 0);
    }

    public function test_un_document_partage_avec_une_autre_structure_reste_inaccessible(): void
    {
        $etudes = Structure::where('code', 'SERV-ETUDES')->firstOrFail();
        $this->actingAs($this->agent('DRH001'), 'sanctum')->post('/api/partages', [
            'titre' => 'Restreint', 'structure_ids' => [$etudes->id],
            'fichier' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $pieceId = \App\Models\Partage::firstOrFail()->piece_id;

        $this->actingAs($this->agent('AGT001'), 'sanctum')->get("/api/pieces/{$pieceId}")->assertOk();
        $this->actingAs($this->agent('SVC001'), 'sanctum')->get("/api/pieces/{$pieceId}")->assertForbidden();
    }

    public function test_validation_des_cibles_et_du_contenu(): void
    {
        $this->actingAs($this->agent('DRH001'), 'sanctum')->postJson('/api/partages', ['titre' => 'Sans cible', 'message' => 'x'])->assertUnprocessable();
        $this->actingAs($this->agent('DRH001'), 'sanctum')->postJson('/api/partages', ['titre' => 'Vide', 'toutes_structures' => true])->assertUnprocessable();
    }
}
