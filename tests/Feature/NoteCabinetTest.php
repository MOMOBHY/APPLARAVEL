<?php

namespace Tests\Feature;

use App\Models\NoteService;
use App\Models\Structure;
use App\Models\User;
use App\Notifications\NoteServiceTransmise;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Le directeur de cabinet rédige, valide puis diffuse lui-même ses notes de service à tous les agents du
 * ministère : toutes les structures sont destinataires et l'email part à tous les agents actifs. */
class NoteCabinetTest extends TestCase
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

    /** Le directeur de cabinet rédige sa note ; les structures choisies sont ignorées : elle s'adresse
     * à tout le ministère. */
    private function rediger(string $objet): array
    {
        $uneSeule = Structure::value('id');

        return $this->actingAs($this->user('CAB001'), 'sanctum')
            ->postJson('/api/notes', [
                'objet' => $objet,
                'contenu' => 'Contenu de la note du Cabinet.',
                'structure_ids' => [$uneSeule],
            ])
            ->assertCreated()
            ->json();
    }

    public function test_la_note_du_cabinet_s_adresse_a_toutes_les_structures(): void
    {
        $note = $this->rediger('Note du Cabinet à tout le ministère');

        $this->assertSame(
            Structure::count(),
            NoteService::findOrFail($note['note_id'])->structures()->count(),
        );
    }

    public function test_le_cabinet_valide_puis_diffuse_a_tous_les_agents(): void
    {
        $cabinet = $this->user('CAB001');
        $note = $this->rediger('Note du Cabinet à diffuser');

        // Un brouillon ne part pas : le directeur de cabinet le valide d'abord.
        $this->actingAs($cabinet, 'sanctum')
            ->postJson("/api/notes/{$note['note_id']}/diffuser-directement")
            ->assertStatus(422);
        $this->actingAs($cabinet, 'sanctum')
            ->postJson("/api/notes/{$note['note_id']}/valider-directement")
            ->assertOk()
            ->assertJsonPath('data.statut', NoteService::VALIDEE);

        Notification::fake();
        $this->actingAs($cabinet, 'sanctum')
            ->postJson("/api/notes/{$note['note_id']}/diffuser-directement")
            ->assertOk()
            ->assertJsonPath('data.statut', NoteService::DIFFUSEE);

        // L'email part à tous les agents actifs du ministère, pas seulement à la structure du Cabinet.
        foreach (User::where('actif', true)->get() as $user) {
            Notification::assertSentTo($user, NoteServiceTransmise::class);
        }
        $this->assertDatabaseHas('notifications', [
            'agent_id' => $this->user('AGT001')->agent_id,
            'reference_dossier' => $note['ref'],
            'type' => 'NOTE_SERVICE',
        ]);
        $this->assertDatabaseHas('note_historique', [
            'note_id' => $note['note_id'],
            'action' => 'DIFFUSION_DIRECTE',
            'acteur_role' => 'ROLE_DIRECTEUR_CABINET',
        ]);
    }

    /** Un agent de n'importe quelle structure la retrouve, avec « Tous les Services du Ministère » comme
     * destinataires. */
    public function test_l_agent_recoit_la_note_du_cabinet(): void
    {
        $cabinet = $this->user('CAB001');
        $note = $this->rediger('Note du Cabinet reçue par les agents');
        $this->actingAs($cabinet, 'sanctum')->postJson("/api/notes/{$note['note_id']}/valider-directement")->assertOk();
        Notification::fake();
        $this->actingAs($cabinet, 'sanctum')->postJson("/api/notes/{$note['note_id']}/diffuser-directement")->assertOk();

        $recue = collect(
            $this->actingAs($this->user('AGT001'), 'sanctum')->getJson('/api/notes')->json('notes'),
        )->firstWhere('id', $note['note_id']);

        $this->assertNotNull($recue, "L'agent doit voir la note diffusée par le Cabinet.");
        $this->assertSame('Tous les Services du Ministère', $recue['service']);
        $this->assertTrue($recue['diffusion_directe']);
    }

    /** La note du Cabinet n'entre pas dans le circuit de la secrétaire. */
    public function test_la_secretaire_n_intervient_pas_sur_la_note_du_cabinet(): void
    {
        $note = $this->rediger('Note du Cabinet hors secrétariat');

        $this->actingAs($this->user('SEC001'), 'sanctum')
            ->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $note['note_id']])
            ->assertForbidden();
    }

    /** La page des notes donne au directeur de cabinet son bloc « envoyées », avec Valider et Diffuser. */
    public function test_la_page_des_notes_permet_au_cabinet_de_rediger_et_diffuser(): void
    {
        $html = file_get_contents(base_path('public/gfp/views/notes.html'));
        $this->assertIsString($html);

        $this->assertStringContainsString('id="blocCabinet"', $html);
        // Deux menus : les notes reçues et, à part, les notes envoyées.
        $this->assertStringContainsString('id="nav-envoyees"', $html);
        $this->assertStringContainsString('id="tab-envoyees"', $html);
        $this->assertStringContainsString("document.getElementById('nav-envoyees').classList.toggle('hidden', !estCabinet);", $html);
        $this->assertStringContainsString('id="formNoteCabinet"', $html);
        $this->assertStringContainsString("const estCabinet = codes.includes('ROLE_DIRECTEUR_CABINET');", $html);
        $this->assertStringContainsString('onclick="enregistrerNoteCabinet(true)"', $html);
        $this->assertStringContainsString('async function validerNoteCabinet(id)', $html);
        $this->assertStringContainsString('async function diffuserNoteCabinet(id)', $html);
        $this->assertStringContainsString('API.validerNoteDirecte(', $html);
        $this->assertStringContainsString('API.diffuserNoteDirecte(', $html);
        $this->assertStringContainsString('Notes de service envoyées', $html);
        $this->assertStringContainsString("'Notes de service reçues'", $html);
    }
}
