<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tableau de bord du directeur (ou sous-directeur) : un dossier qu'il déclare conforme et envoie au DRH
 * reste dans son tableau, marqué comme traité par lui, et fait monter ses compteurs. */
class TableauBordDirecteurTest extends TestCase
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

    /** Ligne du dossier dans /api/requests vue par ce compte. */
    private function ligne(string $matricule, string $code): ?array
    {
        return collect(
            $this->actingAs($this->user($matricule), 'sanctum')->getJson('/api/requests')->assertOk()->json('requests'),
        )->firstWhere('id', $code);
    }

    public function test_le_dossier_envoye_au_drh_reste_traite_par_le_directeur(): void
    {
        $jour = now()->addDays(7)->toDateString();
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions', [
                'typePerm' => 'Événement Familial', 'motif' => 'Baptême',
                'dateDebut' => $jour, 'dateFin' => $jour, 'jours' => 1,
            ])
            ->assertCreated()
            ->json('code');
        $id = $this->ligne('RH001', $code)['dossier_id'];
        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/permissions/{$id}/verifier", ['decision' => 'transmettre'])
            ->assertOk();

        // Le signataire dépend de la structure de l'agent.
        $signataire = $this->ligne('RH001', $code)['visa_attendu'] === 'SOUS_DIRECTEUR' ? 'SD001' : 'DIR001';

        $avant = $this->ligne($signataire, $code);
        $this->assertSame('EN_ATTENTE_VALIDATION_'.($signataire === 'SD001' ? 'SOUS_DIRECTEUR' : 'DIRECTEUR'), $avant['etape']);
        $this->assertFalse($avant['traite_par_moi'], 'À la réception, le dossier est à traiter, pas encore traité.');

        $this->actingAs($this->user($signataire), 'sanctum')
            ->postJson("/api/permissions/{$id}/viser", ['favorable' => true])
            ->assertOk();

        $apres = $this->ligne($signataire, $code);
        $this->assertNotNull($apres, 'Le dossier reste dans le tableau de bord du directeur.');
        $this->assertSame('EN_ATTENTE_DRH', $apres['etape']);
        $this->assertTrue($apres['traite_par_moi'], 'Le dossier déclaré conforme compte comme traité par le directeur.');

        // Un autre directeur ne le voit pas comme traité par lui.
        $autre = $signataire === 'SD001' ? 'DIR001' : 'SD001';
        $this->assertFalse($this->ligne($autre, $code)['traite_par_moi']);
    }

    public function test_l_interface_affiche_valide_et_compte_les_dossiers_traites(): void
    {
        $html = file_get_contents(base_path('public/gfp/views/responsable.html'));

        $this->assertStringContainsString('function statutDashboard(r)', $html);
        $this->assertStringContainsString("return direction && r.traite_par_moi && statut === 'EN_ATTENTE_DRH' ? 'VALIDEE' : statut;", $html);
        $this->assertStringContainsString('(r) => r.matricule === user.matricule || r.traite_par_moi,', $html);
        $this->assertStringContainsString('Transmis au DRH', $html);
    }
}
