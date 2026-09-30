<?php

namespace Tests\Feature;

use App\Models\Structure;
use App\Models\User;
use App\Notifications\NoteServiceTransmise;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Diffusion par la secrétaire : email à tous les agents actifs, quelles que soient les structures ; la
 * diffusion directe du DRH reste limitée à ses structures. */
class EmailDiffusionNotesTest extends TestCase
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

    /** Structure où ne travaille aucun des comptes de démonstration. */
    private function structureVide(): int
    {
        return Structure::whereNotIn('id', User::whereNotNull('structure_id')->pluck('structure_id'))
            ->whereDoesntHave('agents')
            ->value('id') ?? Structure::create(['nom' => 'Structure de test', 'code' => 'TST', 'type' => 'Service'])->id;
    }

    public function test_la_diffusion_de_la_secretaire_envoie_l_email_a_tous_les_agents(): void
    {
        $secretaire = $this->user('SEC001');
        $suspendu = $this->user('CAB001');
        $suspendu->update(['actif' => false]);

        // Note adressée à une structure sans agent : seul l'envoi « à tous » peut toucher quelqu'un.
        $noteId = $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note pour tout le ministère', 'contenu' => 'Texte.', 'structure_ids' => [$this->structureVide()]])
            ->assertCreated()
            ->json('note_id');
        $this->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $noteId])->assertOk();
        $this->actingAs($this->user('DIR001'), 'sanctum')->postJson("/api/notes/{$noteId}/valider")->assertOk();

        Notification::fake();
        $reponse = $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertOk();

        $actifs = User::where('actif', true)->get();
        foreach ($actifs as $user) {
            Notification::assertSentTo($user, NoteServiceTransmise::class);
        }
        Notification::assertNotSentTo($suspendu, NoteServiceTransmise::class);

        $reponse->assertJsonPath('emails.envoyes', $actifs->count())
            ->assertJsonPath('emails.echecs', 0);
    }

    public function test_un_compte_sans_adresse_est_compte_comme_ignore(): void
    {
        $secretaire = $this->user('SEC001');
        User::where('matricule', 'AGT001')->update(['email' => '']);

        $noteId = $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note de test', 'contenu' => 'Texte.'])
            ->json('note_id');
        $this->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $noteId])->assertOk();
        $this->actingAs($this->user('DIR001'), 'sanctum')->postJson("/api/notes/{$noteId}/valider")->assertOk();

        Notification::fake();
        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertOk()
            ->assertJsonPath('emails.ignores', 1)
            ->assertJsonPath('emails.envoyes', User::where('actif', true)->count() - 1);
        Notification::assertNotSentTo($this->user('AGT001'), NoteServiceTransmise::class);
    }

    public function test_la_diffusion_directe_du_drh_reste_limitee_a_ses_structures(): void
    {
        $drh = $this->user('DRH001');
        $noteId = $this->actingAs($drh, 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note de la DRH', 'contenu' => 'Texte.', 'structure_ids' => [$this->structureVide()]])
            ->assertCreated()
            ->json('note_id');

        Notification::fake();
        $this->postJson("/api/notes/{$noteId}/diffuser-directement")->assertOk();

        Notification::assertNothingSent();
    }
}
