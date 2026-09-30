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

/** Circuit d'une note : la secrétaire rédige et envoie aux directeurs, qui valident ou refusent ; un
 * refus la renvoie à la secrétaire, qui la reprend. */
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

    /** La secrétaire rédige une note : elle reste en brouillon. */
    private function rediger(User $secretaire, string $objet, ?array $structures = null): array
    {
        return $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', array_filter([
                'objet' => $objet,
                'contenu' => 'Contenu rédigé par le secrétariat.',
                'structure_ids' => $structures,
            ]))
            ->assertCreated()
            ->json();
    }

    public function test_agent_ne_peut_pas_emettre_une_note(): void
    {
        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note interdite'])
            ->assertForbidden();
    }

    public function test_admin_hors_circuit_notes(): void
    {
        $this->actingAs($this->user('ADM001'), 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note admin'])
            ->assertForbidden();
    }

    /** Seules la secrétaire et la DRH rédigent : ni directeur, ni sous-directeur, ni les autres. */
    public function test_seuls_la_secretaire_et_la_drh_redigent(): void
    {
        $this->rediger($this->user('SEC001'), 'Note du secrétariat');

        foreach (['CAB001', 'SD001', 'DIR001', 'CHEF001', 'RH001'] as $matricule) {
            $this->actingAs($this->user($matricule), 'sanctum')
                ->postJson('/api/notes', ['objet' => 'Note interdite'])
                ->assertForbidden();
        }

        // La DRH rédige ses notes vers sa direction.
        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note autorisée de la DRH'])
            ->assertCreated();
    }

    public function test_la_secretaire_envoie_la_note_et_les_directeurs_sont_prevenus(): void
    {
        $note = $this->rediger($this->user('SEC001'), 'Organisation du service');

        $this->assertEquals(NoteService::BROUILLON, $note['statut']);

        $this->actingAs($this->user('SEC001'), 'sanctum')
            ->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $note['note_id']])
            ->assertOk()
            ->assertJsonPath('statut', NoteService::EN_ATTENTE_VALIDATION);

        $this->assertDatabaseHas('notifications', [
            'agent_id' => $this->user('DIR001')->agent_id,
            'reference_dossier' => $note['ref'],
            'type' => 'ATTENTE_VALIDATION',
        ]);
    }

    public function test_chaine_secretaire_directeurs_validation_diffusion(): void
    {
        $directeur = $this->user('DIR001');
        $secretaire = $this->user('SEC001');
        $agentUser = $this->user('AGT001');
        $structureId = $agentUser->agent->structure_id;

        $created = $this->rediger($secretaire, 'Organisation du service', [$structureId]);
        $noteId = $created['note_id'];

        // La secrétaire envoie aux directeurs.
        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $noteId])
            ->assertOk()
            ->assertJsonPath('statut', NoteService::EN_ATTENTE_VALIDATION);

        // Elle ne peut pas valider elle-même.
        $this->actingAs($secretaire, 'sanctum')
            ->postJson("/api/notes/{$noteId}/valider")
            ->assertForbidden();

        // Ni le sous-directeur, ni le DRH : seuls les directeurs valident.
        $this->actingAs($this->user('SD001'), 'sanctum')
            ->postJson("/api/notes/{$noteId}/valider")
            ->assertForbidden();
        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson("/api/notes/{$noteId}/valider")
            ->assertForbidden();

        // Un directeur valide : c'est le feu vert de diffusion.
        $this->actingAs($directeur, 'sanctum')
            ->postJson("/api/notes/{$noteId}/valider")
            ->assertOk()
            ->assertJsonPath('data.statut', NoteService::VALIDEE);

        // La secrétaire diffuse aux services.
        Notification::fake();
        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertOk();
        $this->assertDatabaseHas('notes_service', [
            'id' => $noteId,
            'statut' => NoteService::DIFFUSEE,
        ]);
        Notification::assertSentTo($agentUser, NoteServiceTransmise::class);

        $actions = NoteHistorique::where('note_id', $noteId)->pluck('action')->all();
        foreach (
            [
                'REDACTION',
                'ENVOI_DIRECTEURS',
                'VALIDATION',
                'DIFFUSION',
            ] as $attendue
        ) {
            $this->assertContains($attendue, $actions);
        }

        $this->actingAs($directeur, 'sanctum')
            ->getJson("/api/notes/{$noteId}/destinataires")
            ->assertOk()
            ->assertJsonStructure(['structures', 'agents']);
    }

    /** Le refus n'arrête pas le circuit : la note revient à la secrétaire, qui la reprend et la renvoie
     * aux directeurs. */
    public function test_refus_du_directeur_renvoie_la_note_au_secretariat_puis_boucle(): void
    {
        $directeur = $this->user('DIR001');
        $secretaire = $this->user('SEC001');
        $note = $this->rediger($secretaire, 'Note à corriger');
        $noteId = $note['note_id'];

        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $noteId])
            ->assertOk();

        $this->actingAs($directeur, 'sanctum')
            ->postJson("/api/notes/{$noteId}/refuser", ['motif' => 'Contenu inexact'])
            ->assertOk()
            ->assertJsonPath('data.statut', NoteService::A_REPRENDRE);

        $this->assertDatabaseHas('notifications', [
            'agent_id' => $secretaire->agent_id,
            'reference_dossier' => $note['ref'],
            'type' => 'REFUS_VALIDATION',
        ]);

        // La secrétaire reprend (corrige) puis renvoie aux directeurs.
        $this->actingAs($secretaire, 'sanctum')
            ->postJson("/api/notes/{$noteId}/reprendre", ['contenu' => 'Version corrigée'])
            ->assertOk();
        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $noteId])
            ->assertOk()
            ->assertJsonPath('statut', NoteService::EN_ATTENTE_VALIDATION);

        // Le directeur valide cette fois : la note est diffusable.
        $this->actingAs($directeur, 'sanctum')
            ->postJson("/api/notes/{$noteId}/valider")
            ->assertOk()
            ->assertJsonPath('data.statut', NoteService::VALIDEE);

        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertOk();
        $this->assertDatabaseHas('notes_service', [
            'id' => $noteId,
            'statut' => NoteService::DIFFUSEE,
        ]);
    }

    /** Pas de diffusion sans le feu vert d'un directeur, et pas de refus sans motif. */
    public function test_diffusion_bloquee_tant_que_le_directeur_n_a_pas_valide(): void
    {
        $directeur = $this->user('DIR001');
        $secretaire = $this->user('SEC001');
        $note = $this->rediger($secretaire, 'Note non validée');
        $noteId = $note['note_id'];

        // Envoyée mais pas validée : la diffusion est refusée.
        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $noteId])
            ->assertOk();

        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertStatus(422);

        // Un refus sans motif n'est pas enregistré.
        $this->actingAs($directeur, 'sanctum')
            ->postJson("/api/notes/{$noteId}/refuser", ['motif' => ''])
            ->assertStatus(422);
        $this->assertDatabaseHas('notes_service', [
            'id' => $noteId,
            'statut' => NoteService::EN_ATTENTE_VALIDATION,
        ]);
    }

    /** N'importe quel directeur reçoit et valide : la première validation l'emporte. */
    public function test_tout_directeur_peut_valider_une_note_envoyee(): void
    {
        $secretaire = $this->user('SEC001');
        $note = $this->rediger($secretaire, 'Note pour tous les directeurs');
        $noteId = $note['note_id'];

        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $noteId])
            ->assertOk();

        $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson("/api/notes/{$noteId}/valider")
            ->assertOk()
            ->assertJsonPath('data.statut', NoteService::VALIDEE);
    }

    /** Le gestionnaire RH consulte les notes de service sans y participer : il les voit toutes, mais ne
     * peut ni les émettre, ni les valider. */
    public function test_le_gestionnaire_rh_consulte_les_notes_sans_y_participer(): void
    {
        $note = $this->rediger($this->user('SEC001'), 'Note visible du gestionnaire');

        $liste = $this->actingAs($this->user('RH001'), 'sanctum')
            ->getJson('/api/notes')
            ->assertOk()
            ->json('notes');
        $this->assertNotEmpty($liste);
        $this->assertContains($note['ref'], array_column($liste, 'numero'));

        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note interdite au gestionnaire'])
            ->assertForbidden();
        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/notes/{$note['note_id']}/valider")
            ->assertForbidden();
    }
}
