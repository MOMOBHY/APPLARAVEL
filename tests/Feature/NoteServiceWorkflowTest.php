<?php

namespace Tests\Feature;

use App\Models\NoteHistorique;
use App\Models\NoteService;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteServiceWorkflowTest extends TestCase
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

    public function test_agent_ne_peut_pas_emettre_une_note(): void
    {
        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note interdite'])
            ->assertForbidden();
    }

    public function test_chaine_autorite_secretaire_validation_diffusion(): void
    {
        $drh = $this->user('DRH001');
        $secretaire = $this->user('SEC001');
        $agentUser = $this->user('AGT001');
        $structureId = $agentUser->agent->structure_id;

        // Émission (ancien contrat : rédaction + secrétariat).
        $created = $this->actingAs($drh, 'sanctum')->postJson('/api/notes', [
            'objet' => 'Organisation du service',
            'contenu' => 'Contenu initial',
            'structure_ids' => [$structureId],
        ])->assertCreated()->json();

        $this->assertEquals(NoteService::EN_ATTENTE_SAISIE, $created['statut']);
        $noteId = $created['note_id'];

        // Pas de diffusion sans validation (le legacy avance la saisie, pas la validation).
        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertStatus(422);

        // Seconde note pour la chaîne complète via les routes dédiées.
        $second = $this->actingAs($drh, 'sanctum')->postJson('/api/notes', [
            'objet' => 'Seconde note de test',
            'structure_ids' => [$structureId],
        ])->assertCreated()->json();
        $noteId = $second['note_id'];

        // Saisie secrétaire.
        $this->actingAs($secretaire, 'sanctum')
            ->postJson("/api/notes/{$noteId}/saisir", ['contenu' => 'Contenu mis en forme par le secrétariat'])
            ->assertOk()->assertJsonPath('data.statut', NoteService::EN_ATTENTE_VALIDATION);

        // Seule l'autorité émettrice valide.
        $this->actingAs($secretaire, 'sanctum')
            ->postJson("/api/notes/{$noteId}/valider")
            ->assertStatus(403);
        $this->actingAs($drh, 'sanctum')
            ->postJson("/api/notes/{$noteId}/valider")
            ->assertOk()->assertJsonPath('data.statut', NoteService::VALIDEE);

        // Diffusion secrétaire.
        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertOk();

        $this->assertDatabaseHas('notes_service', ['id' => $noteId, 'statut' => NoteService::DIFFUSEE]);

        // Consultation auto par l'agent destinataire (note diffusée visible, brouillon non visible).
        $this->actingAs($agentUser, 'sanctum')->getJson('/api/notes')->assertOk()
            ->assertJsonFragment(['numero' => $second['ref']])
            ->assertJsonMissing(['numero' => $created['ref']]);

        // Destinataires exacts pour l'émetteur.
        $this->actingAs($drh, 'sanctum')->getJson("/api/notes/{$noteId}/destinataires")
            ->assertOk()->assertJsonStructure(['structures', 'agents']);

        $actions = NoteHistorique::where('note_id', $noteId)->pluck('action')->all();
        foreach (['REDACTION', 'TRANSMISSION_SECRETARIAT', 'SAISIE_MISE_EN_FORME', 'VALIDATION', 'DIFFUSION'] as $attendue) {
            $this->assertContains($attendue, $actions);
        }
    }

    public function test_admin_hors_circuit_notes(): void
    {
        $admin = $this->user('ADM001');
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note admin'])
            ->assertForbidden();
    }

    public function test_chef_service_emet_gestionnaire_refuse(): void
    {
        $note = $this->actingAs($this->user('CHEF001'), 'sanctum')->postJson('/api/notes', [
            'objet' => 'Note du chef de service',
        ])->assertCreated()->json();
        $this->assertEquals(\App\Models\NoteService::EN_ATTENTE_SAISIE, $note['statut']);

        $this->actingAs($this->user('RH001'), 'sanctum')->postJson('/api/notes', [
            'objet' => 'Note du gestionnaire',
        ])->assertForbidden();
    }

    public function test_refus_motive_avant_diffusion(): void
    {
        $drh = $this->user('DRH001');
        $secretaire = $this->user('SEC001');
        $note = $this->actingAs($drh, 'sanctum')->postJson('/api/notes', [
            'objet' => 'Note à refuser',
        ])->assertCreated()->json();
        $noteId = $note['note_id'];

        $this->actingAs($secretaire, 'sanctum')
            ->postJson("/api/notes/{$noteId}/saisir", ['contenu' => 'Contenu saisi'])
            ->assertOk();

        $this->actingAs($drh, 'sanctum')
            ->postJson("/api/notes/{$noteId}/refuser", ['motif' => 'Contenu inexact'])
            ->assertOk()->assertJsonPath('data.statut', \App\Models\NoteService::REJETEE);

        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertStatus(422);
    }
}
