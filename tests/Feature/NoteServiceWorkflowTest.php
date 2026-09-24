<?php

namespace Tests\Feature;

use App\Models\NoteHistorique;
use App\Models\NoteService;
use App\Models\User;
use App\Notifications\NoteServiceTransmise;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

        // La secrétaire saisit puis transmet directement aux destinataires (email + notification).
        Notification::fake();
        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertOk();
        $this->assertDatabaseHas('notes_service', ['id' => $noteId, 'statut' => NoteService::DIFFUSEE]);
        Notification::assertSentTo($agentUser, NoteServiceTransmise::class);

        // Seconde note, non encore transmise : invisible pour l'agent.
        $brouillon = $this->actingAs($drh, 'sanctum')->postJson('/api/notes', [
            'objet' => 'Note encore au secrétariat',
            'structure_ids' => [$structureId],
        ])->assertCreated()->json();

        // Troisième note pour la chaîne complète via les routes dédiées (validation facultative).
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

        // Consultation auto par l'agent destinataire (notes transmises visibles, note au secrétariat non visible).
        $this->actingAs($agentUser, 'sanctum')->getJson('/api/notes')->assertOk()
            ->assertJsonFragment(['numero' => $second['ref']])
            ->assertJsonFragment(['numero' => $created['ref']])
            ->assertJsonMissing(['numero' => $brouillon['ref']])
            ->assertJsonMissing(['numero_reference' => $brouillon['ref']]);

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

    /** Émetteurs : DRH, Directeur de Cabinet, Directeur, Sous-Directeur ; le Chef de service est destinataire. */
    public function test_directeur_de_cabinet_emet_chef_service_et_gestionnaire_refuses(): void
    {
        $note = $this->actingAs($this->user('CAB001'), 'sanctum')->postJson('/api/notes', [
            'objet' => 'Note du directeur de cabinet',
        ])->assertCreated()->json();
        $this->assertEquals(NoteService::EN_ATTENTE_SAISIE, $note['statut']);

        // Transmise à sa secrétaire (même structure).
        $this->assertDatabaseHas('notifications', [
            'agent_id' => $this->user('SEC001')->agent_id, 'reference_dossier' => $note['ref'], 'type' => 'ATTENTE_SAISIE',
        ]);

        $this->actingAs($this->user('CHEF001'), 'sanctum')->postJson('/api/notes', [
            'objet' => 'Note du chef de service',
        ])->assertForbidden();

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
            ->assertOk()->assertJsonPath('data.statut', NoteService::REJETEE);

        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertStatus(422);
    }
}
