<?php

namespace Tests\Feature;

use App\Mail\NoteServiceEnvoyee;
use App\Models\Agent;
use App\Models\NoteService;
use App\Models\Notification;
use App\Models\Structure;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Notes de service : la secrétaire dépose un document Word et le transmet à son directeur ; le directeur
 * valide ou retourne avec un motif ; la secrétaire envoie la note validée aux destinataires choisis, par
 * e-mail (document joint) et dans l'application. */
class NotesDeServiceTest extends TestCase
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

    private function word(string $nom = 'note-acces.docx'): UploadedFile
    {
        return UploadedFile::fake()->create(
            $nom,
            40,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
    }

    /** Dépôt (et transmission immédiate si demandé) par la secrétaire du Cabinet. */
    private function deposer(bool $transmettre = true): NoteService
    {
        $id = $this->actingAs($this->user('SEC001'), 'sanctum')
            ->post('/api/notes', [
                'objet' => 'Réglementation de l’accès au Ministère d’État',
                'date_note' => '2026-04-28',
                'fichier' => $this->word(),
                'transmettre' => $transmettre ? '1' : '0',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        return NoteService::findOrFail($id);
    }

    private function valider(NoteService $note): void
    {
        $this->actingAs($this->user('CAB001'), 'sanctum')
            ->postJson("/api/notes/{$note->id}/valider")
            ->assertOk();
    }

    public function test_la_secretaire_depose_et_transmet_a_son_directeur(): void
    {
        $note = $this->deposer();
        $directeur = $this->user('CAB001')->agent;

        $this->assertSame(NoteService::EN_ATTENTE_VALIDATION, $note->statut);
        $this->assertSame($directeur->id, $note->directeur_id, 'Le directeur de la secrétaire du Cabinet est le Directeur de Cabinet.');
        $this->assertMatchesRegularExpression('#^N° 0001/MFP/CAB/\d{4}$#', $note->numero);
        Storage::disk('local')->assertExists($note->fichier_path);
        $this->assertTrue(
            Notification::where('agent_id', $directeur->id)->where('type', 'NOTE_A_VALIDER')->exists(),
            'Le directeur est prévenu qu’une note attend sa validation.',
        );

        $vue = $this->actingAs($this->user('CAB001'), 'sanctum')->getJson('/api/notes')->assertOk();
        $this->assertSame([$note->numero], collect($vue->json('a_valider'))->pluck('numero')->all());
    }

    public function test_seul_un_document_word_est_accepte(): void
    {
        $this->actingAs($this->user('SEC001'), 'sanctum')
            ->post('/api/notes', [
                'objet' => 'Note en PDF',
                'date_note' => '2026-04-28',
                'fichier' => UploadedFile::fake()->create('note.pdf', 40, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fichier');
        $this->assertSame(0, NoteService::count());
    }

    public function test_seule_la_secretaire_depose_une_note(): void
    {
        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->post('/api/notes', [
                'objet' => 'Note', 'date_note' => '2026-04-28', 'fichier' => $this->word(),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_le_directeur_valide_puis_la_secretaire_envoie_a_tous(): void
    {
        Mail::fake();
        $note = $this->deposer();
        $this->valider($note);
        $this->assertSame(NoteService::VALIDEE, $note->refresh()->statut);
        $this->assertTrue(
            Notification::where('agent_id', $note->secretaire_id)->where('type', 'NOTE_VALIDEE')->exists(),
        );

        $reponse = $this->actingAs($this->user('SEC001'), 'sanctum')
            ->postJson("/api/notes/{$note->id}/envoyer", ['tous' => true])
            ->assertOk();

        $this->assertSame(NoteService::ENVOYEE, $note->refresh()->statut);
        $this->assertSame(Agent::count(), $reponse->json('destinataires'));
        $this->assertSame(Agent::count(), Notification::where('type', 'NOTE_SERVICE')->count());
        $comptesAvecAdresse = User::whereNotNull('email')->count();
        Mail::assertSent(NoteServiceEnvoyee::class, $comptesAvecAdresse);
        Mail::assertSent(
            NoteServiceEnvoyee::class,
            fn (NoteServiceEnvoyee $mail) => $mail->note->is($note) && count($mail->attachments()) === 1,
        );
        $this->assertSame($comptesAvecAdresse, $reponse->json('emails.envoyes'));

        // L'agent la reçoit dans l'application et peut ouvrir le document.
        $agent = $this->actingAs($this->user('AGT001'), 'sanctum');
        $recue = collect($agent->getJson('/api/notes')->json('recues'))->firstWhere('id', $note->id);
        $this->assertNotNull($recue);
        $this->assertFalse($recue['lu']);
        $agent->get("/api/notes/{$note->id}/fichier")->assertOk()->assertDownload('note-acces.docx');
        $recue = collect($agent->getJson('/api/notes')->json('recues'))->firstWhere('id', $note->id);
        $this->assertTrue($recue['lu'], 'Ouvrir le document marque la note comme lue.');
    }

    public function test_envoi_a_des_structures_et_a_des_agents_choisis(): void
    {
        Mail::fake();
        $note = $this->deposer();
        $this->valider($note);
        $drh = Structure::where('code', 'DRH')->firstOrFail();
        $agt = $this->user('AGT001')->agent;

        $this->actingAs($this->user('SEC001'), 'sanctum')
            ->postJson("/api/notes/{$note->id}/envoyer", ['structures' => [$drh->id], 'agents' => [$agt->id]])
            ->assertOk();

        $attendus = Agent::where('structure_id', $drh->id)->pluck('id')->push($agt->id)->unique()->sort()->values();
        $this->assertEquals($attendus->all(), $note->destinataires()->orderBy('agent_id')->pluck('agent_id')->all());
        $this->assertTrue((bool) $note->destinataires()->where('agent_id', $agt->id)->value('choisi'));
        $this->assertStringContainsString('Direction des Ressources Humaines', $this->actingAs($this->user('SEC001'), 'sanctum')
            ->getJson('/api/notes')->json('mes_notes.0.destinataires.resume'));

        // Un agent de la DSI n'est pas destinataire : il ne la voit pas et ne peut pas l'ouvrir.
        $dsi = $this->actingAs($this->user('DIR001'), 'sanctum');
        $this->assertEmpty($dsi->getJson('/api/notes')->json('recues'));
        $dsi->getJson("/api/notes/{$note->id}/fichier")->assertForbidden();
    }

    public function test_le_directeur_retourne_puis_la_secretaire_charge_une_nouvelle_version(): void
    {
        $note = $this->deposer();
        $this->actingAs($this->user('CAB001'), 'sanctum')
            ->postJson("/api/notes/{$note->id}/retourner", ['motif' => 'Préciser la date d’entrée en vigueur.'])
            ->assertOk();
        $this->assertSame(NoteService::A_CORRIGER, $note->refresh()->statut);
        $this->assertSame('Préciser la date d’entrée en vigueur.', $note->motif_retour);
        $this->assertTrue(
            Notification::where('agent_id', $note->secretaire_id)->where('type', 'NOTE_RETOURNEE')->exists(),
        );

        $secretaire = $this->actingAs($this->user('SEC001'), 'sanctum');
        $secretaire->post("/api/notes/{$note->id}/document", ['fichier' => $this->word('note-acces-v2.docx')], ['Accept' => 'application/json'])
            ->assertOk();
        $secretaire->postJson("/api/notes/{$note->id}/transmettre")->assertOk();

        $note->refresh();
        $this->assertSame(NoteService::EN_ATTENTE_VALIDATION, $note->statut);
        $this->assertSame('note-acces-v2.docx', $note->fichier_nom);
        $this->assertNull($note->motif_retour);
        $this->assertSame(
            ['DEPOT', 'TRANSMISSION', 'RETOUR', 'NOUVELLE_VERSION', 'TRANSMISSION'],
            $note->historique()->pluck('action')->all(),
        );
    }

    public function test_seul_le_directeur_de_la_note_la_valide(): void
    {
        $note = $this->deposer();
        foreach (['DIR001', 'DRH001', 'AGT001'] as $autre) {
            $this->actingAs($this->user($autre), 'sanctum')
                ->postJson("/api/notes/{$note->id}/valider")
                ->assertForbidden();
        }
        $this->assertSame(NoteService::EN_ATTENTE_VALIDATION, $note->refresh()->statut);
    }

    public function test_l_envoi_exige_une_note_validee(): void
    {
        $note = $this->deposer();
        $this->actingAs($this->user('SEC001'), 'sanctum')
            ->postJson("/api/notes/{$note->id}/envoyer", ['tous' => true])
            ->assertStatus(422);
        $this->assertSame(0, $note->destinataires()->count());
    }

    public function test_l_envoi_exige_au_moins_un_destinataire(): void
    {
        $note = $this->deposer();
        $this->valider($note);
        $this->actingAs($this->user('SEC001'), 'sanctum')
            ->postJson("/api/notes/{$note->id}/envoyer", ['structures' => [], 'agents' => []])
            ->assertStatus(422);
        $this->assertSame(NoteService::VALIDEE, $note->refresh()->statut);
    }

    public function test_sans_directeur_la_note_ne_peut_pas_etre_transmise(): void
    {
        $this->user('CAB001')->update(['actif' => false]);
        $this->actingAs($this->user('SEC001'), 'sanctum')
            ->post('/api/notes', [
                'objet' => 'Note', 'date_note' => '2026-04-28', 'fichier' => $this->word(), 'transmettre' => '1',
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame(0, NoteService::count());
    }

    public function test_un_brouillon_se_supprime_mais_pas_une_note_transmise(): void
    {
        $brouillon = $this->deposer(transmettre: false);
        $this->assertSame(NoteService::BROUILLON, $brouillon->statut);
        $secretaire = $this->actingAs($this->user('SEC001'), 'sanctum');
        $secretaire->deleteJson("/api/notes/{$brouillon->id}")->assertOk();
        $this->assertModelMissing($brouillon);
        Storage::disk('local')->assertMissing($brouillon->fichier_path);

        $transmise = $this->deposer();
        $secretaire->deleteJson("/api/notes/{$transmise->id}")->assertStatus(422);
    }

    public function test_la_secretaire_voit_son_directeur_et_les_destinataires_possibles(): void
    {
        $secretaire = $this->actingAs($this->user('SEC001'), 'sanctum');
        $vue = $secretaire->getJson('/api/notes')->assertOk();
        $this->assertTrue($vue->json('secretaire'));
        $this->assertSame($this->user('CAB001')->agent->fullName(), $vue->json('directeur.nom'));

        $choix = $secretaire->getJson('/api/notes/destinataires')->assertOk();
        $this->assertNotEmpty($choix->json('structures'));
        $this->assertSame(Agent::count(), count($choix->json('agents')));

        $this->actingAs($this->user('AGT001'), 'sanctum')->getJson('/api/notes/destinataires')->assertForbidden();
    }
}
