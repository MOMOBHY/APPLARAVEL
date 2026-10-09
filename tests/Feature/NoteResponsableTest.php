<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\NoteService;
use App\Models\User;
use App\Notifications\NoteServiceTransmise;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Le directeur et le sous-directeur rédigent leurs notes, les valident et les diffusent eux-mêmes aux
 * agents, comme le DRH : sans circuit de validation, et sans que la secrétaire puisse les faire entrer
 * dans le sien. */
class NoteResponsableTest extends TestCase
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

    /** L'auteur valide sa note : elle n'est plus un brouillon et peut être diffusée. */
    private function valider(string $matricule, int $noteId): void
    {
        $this->actingAs($this->user($matricule), 'sanctum')
            ->postJson("/api/notes/{$noteId}/valider-directement")
            ->assertOk()
            ->assertJsonPath('data.statut', NoteService::VALIDEE);
    }

    /** Le responsable rédige une note vers sa structure : elle reste en brouillon. */
    private function rediger(string $matricule, string $objet): array
    {
        $auteur = $this->user($matricule);

        return $this->actingAs($auteur, 'sanctum')
            ->postJson('/api/notes', [
                'objet' => $objet,
                'contenu' => 'Contenu de la note.',
                'structure_ids' => [$auteur->agent->structure_id],
            ])
            ->assertCreated()
            ->json();
    }

    public function test_le_directeur_redige_valide_puis_diffuse_directement(): void
    {
        $directeur = $this->user('DIR001');
        $note = $this->rediger('DIR001', 'Note du directeur à sa structure');
        $this->assertEquals(NoteService::BROUILLON, $note['statut']);
        $this->valider('DIR001', $note['note_id']);

        Notification::fake();
        $this->actingAs($directeur, 'sanctum')
            ->postJson("/api/notes/{$note['note_id']}/diffuser-directement")
            ->assertOk()
            ->assertJsonPath('data.statut', NoteService::DIFFUSEE);

        // Les agents de sa structure sont notifiés et reçoivent l'email.
        $agent = Agent::where('structure_id', $directeur->agent->structure_id)
            ->where('id', '!=', $directeur->agent_id)
            ->whereHas('user')
            ->firstOrFail();
        $this->assertDatabaseHas('notifications', [
            'agent_id' => $agent->id,
            'reference_dossier' => $note['ref'],
            'type' => 'NOTE_SERVICE',
        ]);
        Notification::assertSentTo($agent->user, NoteServiceTransmise::class);

        // L'historique garde les deux étapes, sous le rôle réel de l'auteur.
        foreach (['VALIDATION_AUTEUR', 'DIFFUSION_DIRECTE'] as $action) {
            $this->assertDatabaseHas('note_historique', [
                'note_id' => $note['note_id'],
                'action' => $action,
                'acteur_role' => 'ROLE_DIRECTEUR',
            ]);
        }
    }

    public function test_le_sous_directeur_redige_valide_puis_diffuse_directement(): void
    {
        $note = $this->rediger('SD001', 'Note du sous-directeur');
        $this->valider('SD001', $note['note_id']);

        Notification::fake();
        $this->actingAs($this->user('SD001'), 'sanctum')
            ->postJson("/api/notes/{$note['note_id']}/diffuser-directement")
            ->assertOk()
            ->assertJsonPath('data.statut', NoteService::DIFFUSEE);
    }

    /** Un brouillon ne se diffuse pas : l'auteur doit d'abord le valider. Une note validée ne se valide
     * pas deux fois. */
    public function test_la_diffusion_exige_la_validation(): void
    {
        $note = $this->rediger('DIR001', 'Note à valider avant diffusion');

        $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson("/api/notes/{$note['note_id']}/diffuser-directement")
            ->assertStatus(422);

        $this->valider('DIR001', $note['note_id']);
        $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson("/api/notes/{$note['note_id']}/valider-directement")
            ->assertStatus(422);
    }

    /** Seul l'auteur valide et diffuse sa note : ni un autre responsable, ni la DRH. */
    public function test_un_autre_responsable_ne_valide_ni_ne_diffuse_la_note(): void
    {
        $note = $this->rediger('DIR001', 'Note réservée à son auteur');

        foreach (['SD001', 'DRH001'] as $matricule) {
            $this->actingAs($this->user($matricule), 'sanctum')
                ->postJson("/api/notes/{$note['note_id']}/valider-directement")
                ->assertForbidden();
        }

        $this->valider('DIR001', $note['note_id']);
        foreach (['SD001', 'DRH001'] as $matricule) {
            $this->actingAs($this->user($matricule), 'sanctum')
                ->postJson("/api/notes/{$note['note_id']}/diffuser-directement")
                ->assertForbidden();
        }
    }

    /** La secrétaire ne diffuse pas à sa place une note validée par son auteur. */
    public function test_la_secretaire_ne_diffuse_pas_la_note_validee_d_un_responsable(): void
    {
        $note = $this->rediger('DIR001', 'Note validée hors secrétariat');
        $this->valider('DIR001', $note['note_id']);

        $this->actingAs($this->user('SEC001'), 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $note['note_id']])
            ->assertForbidden();
        $this->assertDatabaseHas('notes_service', [
            'id' => $note['note_id'],
            'statut' => NoteService::VALIDEE,
        ]);
    }

    /** La note d'un responsable n'entre pas dans le circuit de la secrétaire. */
    public function test_la_secretaire_n_envoie_pas_la_note_d_un_responsable_aux_directeurs(): void
    {
        $note = $this->rediger('DIR001', 'Note hors circuit secrétariat');

        $this->actingAs($this->user('SEC001'), 'sanctum')
            ->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $note['note_id']])
            ->assertForbidden();
        $this->assertDatabaseHas('notes_service', [
            'id' => $note['note_id'],
            'statut' => NoteService::BROUILLON,
        ]);
    }

    /** L'API indique quelles notes sont diffusées par leur auteur : l'interface de la secrétaire n'y
     * affiche aucun geste. */
    public function test_l_api_signale_les_notes_en_diffusion_directe(): void
    {
        $duDirecteur = $this->rediger('DIR001', 'Note du directeur');
        $duSecretariat = $this->actingAs($this->user('SEC001'), 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note du secrétariat'])
            ->assertCreated()
            ->json();

        $notes = collect(
            $this->actingAs($this->user('SEC001'), 'sanctum')->getJson('/api/notes')->json('notes'),
        );

        $this->assertTrue($notes->firstWhere('id', $duDirecteur['note_id'])['diffusion_directe']);
        $this->assertFalse($notes->firstWhere('id', $duSecretariat['note_id'])['diffusion_directe']);
    }
}
