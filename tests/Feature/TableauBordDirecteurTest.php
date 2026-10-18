<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    /** Gestionnaire RH : une permission ou une déclaration qu'il transmet reste dans son tableau, marquée
     * comme traitée par lui, à une étape postérieure à sa vérification (affichée « Validé »). */
    public function test_le_dossier_transmis_reste_traite_par_le_gestionnaire_rh(): void
    {
        $jour = now()->addDays(7)->toDateString();
        $agent = $this->actingAs($this->user('AGT001'), 'sanctum');
        $permission = $agent->postJson('/api/permissions', [
            'typePerm' => 'Événement Familial', 'motif' => 'Baptême',
            'dateDebut' => $jour, 'dateFin' => $jour, 'jours' => 1,
        ])->assertCreated()->json('code');
        $naissance = $agent->postJson('/api/declarations', [
            'nature' => 'NAISSANCE', 'nom' => 'KONE', 'prenom' => 'Awa',
            'date' => now()->subDays(3)->toDateString(), 'lieu' => 'Abidjan',
            'extrait' => UploadedFile::fake()->create('extrait.pdf', 20, 'application/pdf'),
        ])->assertCreated()->json('code');

        $this->assertFalse($this->ligne('RH001', $permission)['traite_par_moi']);

        $rh = $this->actingAs($this->user('RH001'), 'sanctum');
        $rh->postJson('/api/permissions/'.$this->ligne('RH001', $permission)['dossier_id'].'/verifier', [
            'decision' => 'transmettre',
        ])->assertOk();
        $rh->postJson('/api/naissances/'.$this->ligne('RH001', $naissance)['dossier_id'].'/controler', [
            'decision' => 'conforme',
        ])->assertOk();

        $etapesApresRh = [
            'EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR',
            'EN_ATTENTE_VALIDATION_DIRECTEUR',
            'EN_ATTENTE_VALIDATION_RESPONSABLE',
            'EN_ATTENTE_DRH',
        ];
        foreach ([$permission, $naissance] as $code) {
            $ligne = $this->ligne('RH001', $code);
            $this->assertNotNull($ligne, "{$code} reste dans le tableau de bord du gestionnaire RH.");
            $this->assertTrue($ligne['traite_par_moi'], "{$code} compte comme traité par le gestionnaire RH.");
            $this->assertContains($ligne['etape'], $etapesApresRh);
        }
    }

    public function test_l_interface_affiche_valide_et_compte_les_dossiers_traites(): void
    {
        $html = file_get_contents(base_path('public/gfp/views/responsable.html'));

        $this->assertStringContainsString('function statutDashboard(r)', $html);
        $this->assertStringContainsString('if (!r.traite_par_moi) return statut;', $html);
        $this->assertStringContainsString("if (direction && statut === 'EN_ATTENTE_DRH') return 'VALIDEE';", $html);
        $this->assertStringContainsString(
            "if (user.role === 'RESPONSABLE' && ETAPES_APRES_VERIFICATION_RH.includes(statut)) return 'VALIDEE';",
            $html,
        );
        $this->assertStringContainsString('(r) => r.matricule === user.matricule || r.traite_par_moi,', $html);
        $this->assertStringContainsString('Transmis au DRH', $html);
    }
}
