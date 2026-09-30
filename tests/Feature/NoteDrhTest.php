<?php

namespace Tests\Feature;

use App\Models\NoteService;
use App\Models\User;
use App\Notifications\NoteServiceTransmise;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Le DRH fait ses notes vers les agents de sa direction, sans circuit de validation : il rédige puis
 * diffuse directement. Ni les directeurs ni la secrétaire n'interviennent sur ses notes. */
class NoteDrhTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
    }

    private function user(string $matricule): User
    {
        return User::where('matricule', $matricule)->firstOrFail();
    }

    public function test_le_drh_redige_puis_diffuse_directement(): void
    {
        $drh = $this->user('DRH001');
        $structureId = $drh->agent->structure_id;
        $this->assertNotNull($structureId);

        $created = $this->actingAs($drh, 'sanctum')
            ->postJson('/api/notes', [
                'objet' => 'Note de la DRH vers sa direction',
                'contenu' => 'Contenu de la direction.',
                'structure_ids' => [$structureId],
            ])
            ->assertCreated()
            ->json();
        $this->assertEquals(NoteService::BROUILLON, $created['statut']);
        $noteId = $created['note_id'];

        Notification::fake();
        $this->actingAs($drh, 'sanctum')
            ->postJson("/api/notes/{$noteId}/diffuser-directement")
            ->assertOk()
            ->assertJsonPath('data.statut', NoteService::DIFFUSEE);

        // Les agents de sa direction sont notifiés et destinataires de l'email.
        $agent = \App\Models\Agent::where('structure_id', $structureId)->firstOrFail();
        $this->assertDatabaseHas('notifications', [
            'agent_id' => $agent->id,
            'reference_dossier' => $created['ref'],
            'type' => 'NOTE_SERVICE',
        ]);
        Notification::assertSentTo($agent->user, NoteServiceTransmise::class);
    }

    public function test_la_diffusion_directe_exige_un_brouillon_du_drh(): void
    {
        $drh = $this->user('DRH001');
        $secretaire = $this->user('SEC001');

        // La secrétaire ne diffuse pas en direct : elle suit le circuit classique.
        $note = $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note du secrétariat'])
            ->assertCreated()
            ->json();
        $this->actingAs($drh, 'sanctum')
            ->postJson("/api/notes/{$note['note_id']}/diffuser-directement")
            ->assertForbidden();

        // Un directeur ne diffuse pas en direct non plus.
        $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson("/api/notes/{$note['note_id']}/diffuser-directement")
            ->assertForbidden();

        // Une note déjà diffusée ne repart pas.
        $mienne = $this->actingAs($drh, 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note DRH à diffuser deux fois'])
            ->assertCreated()
            ->json();
        $this->actingAs($drh, 'sanctum')
            ->postJson("/api/notes/{$mienne['note_id']}/diffuser-directement")
            ->assertOk();
        $this->actingAs($drh, 'sanctum')
            ->postJson("/api/notes/{$mienne['note_id']}/diffuser-directement")
            ->assertStatus(422);
    }

    public function test_la_note_du_drh_ne_passe_pas_par_la_validation(): void
    {
        $drh = $this->user('DRH001');
        $note = $this->actingAs($drh, 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note DRH sans validation'])
            ->assertCreated()
            ->json();

        // Aucun directeur n'a rien à valider : la note n'est jamais en attente.
        $this->assertDatabaseMissing('notifications', [
            'reference_dossier' => $note['ref'],
            'type' => 'ATTENTE_VALIDATION',
        ]);

        // La secrétaire ne diffuse pas une note non validée par le circuit classique.
        $this->actingAs($this->user('SEC001'), 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $note['note_id']])
            ->assertStatus(422);
    }
}
