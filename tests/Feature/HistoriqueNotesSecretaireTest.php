<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Onglet « Historique des notes » de la secrétaire : /api/notes lui renvoie la frise de chaque note
 * (étapes, acteurs, motifs de refus, dates), pas aux autres. */
class HistoriqueNotesSecretaireTest extends TestCase
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

    /** Déroule rédaction → envoi → refus → reprise → renvoi → validation → diffusion. */
    private function noteAvecRefusPuisDiffusion(): int
    {
        $secretaire = $this->user('SEC001');
        $directeur = $this->user('DIR001');
        $noteId = $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['objet' => 'Note avec historique', 'contenu' => 'Première version.'])
            ->assertCreated()
            ->json('note_id');

        $this->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $noteId])->assertOk();
        $this->actingAs($directeur, 'sanctum')
            ->postJson("/api/notes/{$noteId}/refuser", ['motif' => 'Date de réunion erronée'])
            ->assertOk();
        $this->actingAs($secretaire, 'sanctum')
            ->postJson("/api/notes/{$noteId}/reprendre", ['contenu' => 'Version corrigée'])
            ->assertOk();
        $this->postJson('/api/notes', ['action' => 'envoyer', 'note_id' => $noteId])->assertOk();
        $this->actingAs($directeur, 'sanctum')->postJson("/api/notes/{$noteId}/valider")->assertOk();
        $this->actingAs($secretaire, 'sanctum')
            ->postJson('/api/notes', ['action' => 'diffuse', 'note_id' => $noteId])
            ->assertOk();

        return $noteId;
    }

    public function test_la_secretaire_recoit_la_frise_complete_de_chaque_note(): void
    {
        $noteId = $this->noteAvecRefusPuisDiffusion();

        $note = collect(
            $this->actingAs($this->user('SEC001'), 'sanctum')->getJson('/api/notes')->assertOk()->json('notes'),
        )->firstWhere('id', $noteId);

        $this->assertSame(
            ['REDACTION', 'ENVOI_DIRECTEURS', 'REFUS_VALIDATION', 'REPRISE', 'ENVOI_DIRECTEURS', 'VALIDATION', 'DIFFUSION'],
            array_column($note['historique'], 'action'),
            'Les étapes sont renvoyées dans l’ordre chronologique.',
        );

        $refus = $note['historique'][2];
        $this->assertSame('Date de réunion erronée', $refus['commentaire']);
        $this->assertSame('ROLE_DIRECTEUR', $refus['role']);
        $this->assertNotEmpty($refus['acteur']);
        // Date ISO 8601 complète : lisible par tous les navigateurs, Safari compris.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $refus['date']);
    }

    public function test_les_autres_profils_ne_recoivent_pas_l_historique(): void
    {
        $noteId = $this->noteAvecRefusPuisDiffusion();

        foreach (['AGT001', 'DIR001', 'DRH001'] as $matricule) {
            $note = collect(
                $this->actingAs($this->user($matricule), 'sanctum')->getJson('/api/notes')->assertOk()->json('notes'),
            )->firstWhere('id', $noteId);

            $this->assertNotNull($note, "{$matricule} voit la note diffusée.");
            $this->assertArrayNotHasKey('historique', $note, "{$matricule} ne reçoit pas la frise interne.");
        }
    }

    public function test_la_secretaire_suit_et_agit_depuis_l_historique(): void
    {
        $html = file_get_contents(base_path('public/gfp/views/notes.html'));

        // Deux onglets pour la secrétaire : ses notes (par défaut) et la rédaction ;
        // plus de tableau « Suivi des notes ».
        $this->assertStringContainsString('id="nav-historique"', $html);
        $this->assertStringContainsString("document.getElementById('blocSuivi').classList.toggle('hidden', peutRediger);", $html);
        $this->assertStringContainsString("document.getElementById('nav-historique').classList.toggle('hidden', !peutRediger);", $html);
        $this->assertStringContainsString("if (peutRediger) showTab('historique');", $html);
        $this->assertStringContainsString("onglet: 'Rédiger une note'", $html);
        $this->assertStringContainsString('function renderHistorique()', $html);
        $this->assertStringContainsString('function exporterHistorique()', $html);
        // Les trois gestes de la secrétaire sont sur les cartes de l'historique.
        $this->assertStringContainsString("BROUILLON: bouton('envoyer', 'Envoyer aux directeurs')", $html);
        $this->assertStringContainsString("A_REPRENDRE: bouton('reprendre', 'Modifier et renvoyer')", $html);
        $this->assertStringContainsString("VALIDEE: bouton('diffuser', 'Diffuser la note')", $html);
    }
}
