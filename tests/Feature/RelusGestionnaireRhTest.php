<?php

namespace Tests\Feature;

use App\Models\DemandePermission;
use App\Models\User;
use App\Services\EtatCivilService;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Le statut filtré par la vue du gestionnaire RH doit être un statut réellement produit par le workflow
 * (la liste était restée vide après un renommage). */
class RelusGestionnaireRhTest extends TestCase
{
    use RefreshDatabase;

    /** Statut que responsable.html attend pour une permission à vérifier. */
    private const ATTENTE_RH = 'EN_ATTENTE_RH';

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

    /** Tous les statuts que les workflows peuvent écrire. */
    private function statutsConnus(): array
    {
        return array_unique(array_merge(
            DemandePermission::STATUTS,
            EtatCivilService::STATUTS_NAISSANCE,
            [EtatCivilService::ARCHIVEE, EtatCivilService::BROUILLON],
        ));
    }

    /** Filet de sécurité : chaque statut filtré par la vue du gestionnaire RH doit exister dans le
     * workflow. */
    public function test_chaque_statut_filtre_par_la_vue_gestionnaire_existe_dans_le_workflow(): void
    {
        $vue = file_get_contents(base_path('public/gfp/views/responsable.html'));
        $this->assertIsString($vue);

        preg_match_all("/etape === '([A-Z_]+)'/", $vue, $trouves);
        $filtres = array_values(array_unique($trouves[1]));

        $this->assertNotEmpty($filtres, 'Aucun filtre de statut trouvé dans responsable.html.');

        foreach ($filtres as $statut) {
            $this->assertContains(
                $statut,
                $this->statutsConnus(),
                "responsable.html filtre sur « {$statut} », statut absent du workflow : la boîte resterait vide.",
            );
        }
    }

    public function test_la_permission_soumise_arrive_avec_le_statut_attendu_par_la_vue(): void
    {
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions/brouillon', [
                'typePerm' => 'Événement Familial',
                'motif' => 'Mariage',
                'dateDebut' => '2026-10-01',
                'dateFin' => '2026-10-03',
                'piece' => UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf'),
            ])->assertCreated()->json('code');

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions/soumettre-brouillon', ['code_dossier' => $code])
            ->assertOk();

        // Le dossier servi au gestionnaire RH, avec le statut que la vue filtre.
        $dossier = collect($this->actingAs($this->user('RH001'), 'sanctum')
            ->getJson('/api/requests')->assertOk()->json('requests'))
            ->firstWhere('id', $code);

        $this->assertNotNull($dossier, 'Le gestionnaire RH ne reçoit pas la permission soumise.');
        $this->assertSame(self::ATTENTE_RH, $dossier['etape']);
        $this->assertSame('DEMANDE_PERMISSION', $dossier['nature']);
    }

    public function test_la_declaration_soumise_arrive_avec_le_statut_attendu_par_la_vue(): void
    {
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/declarations/brouillon', [
                'nature' => 'DECES',
                'nom' => 'YAO',
                'prenom' => 'Koffi',
                'lien_parente' => 'ascendant',
                'date' => '2026-08-20',
                'lieu' => 'Bouaké',
                'certificat' => UploadedFile::fake()->create('certificat.pdf', 100, 'application/pdf'),
            ])->assertCreated()->json('code');

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/declarations/soumettre-brouillon', ['nature' => 'DECES', 'code_dossier' => $code])
            ->assertOk();

        $dossier = collect($this->actingAs($this->user('RH001'), 'sanctum')
            ->getJson('/api/requests')->assertOk()->json('requests'))
            ->firstWhere('id', $code);

        $this->assertNotNull($dossier, 'Le gestionnaire RH ne reçoit pas la déclaration soumise.');
        $this->assertSame(self::ATTENTE_RH, $dossier['etape']);
    }

    public function test_le_gestionnaire_rh_est_notifie_de_la_soumission(): void
    {
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions', [
                'typePerm' => 'Événement Familial',
                'motif' => 'Mariage',
                'dateDebut' => '2026-10-01',
                'dateFin' => '2026-10-03',
                'jours' => 3,
                'piece' => UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf'),
            ])->assertCreated()->json('code');

        // La notification doit être adressée au gestionnaire RH lui-même.
        $rh = $this->user('RH001');
        $this->assertDatabaseHas('notifications', [
            'reference_dossier' => $code,
            'agent_id' => $rh->agent_id,
        ]);
    }

    /** Après vérification du gestionnaire RH, le dossier doit quitter sa boîte et porter le statut du
     * visa attendu par les vues Direction / Sous-Direction. */
    public function test_apres_verification_le_dossier_porte_le_statut_de_visa_attendu(): void
    {
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions', [
                'typePerm' => 'Événement Familial',
                'motif' => 'Mariage',
                'dateDebut' => '2026-10-01',
                'dateFin' => '2026-10-02',
                'jours' => 2,
                'piece' => UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf'),
            ])->assertCreated()->json('code');

        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson('/api/status', ['id' => $code, 'statut' => 'EN_ATTENTE_RH', 'avis' => 'CONFORME'])
            ->assertOk();

        $dossier = collect($this->actingAs($this->user('RH001'), 'sanctum')
            ->getJson('/api/requests')->assertOk()->json('requests'))
            ->firstWhere('id', $code);

        $this->assertNotNull($dossier);
        // Cas court (2 j) : le visa hierarchique est attendu avant la DRH.
        $this->assertContains(
            $dossier['etape'],
            [DemandePermission::EN_ATTENTE_VALIDATION_DIRECTEUR, DemandePermission::EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR],
            'Le dossier devrait attendre un visa, pas rester en attente du gestionnaire RH.',
        );
    }
}
