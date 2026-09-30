<?php

namespace Tests\Feature;

use App\Models\DeclarationDeces;
use App\Models\DeclarationNaissance;
use App\Models\DemandePermission;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Un seul statut réel par dossier : après chaque étape, tous les rôles le retrouvent dans /api/requests
 * et le détail avec ce même statut (3 modules). */
class SynchronisationDashboardsTest extends TestCase
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

    /** Tous les rôles qui suivent l'ensemble des dossiers. */
    private function roles(): array
    {
        return ['AGT001', 'RH001', 'SD001', 'DIR001', 'DRH001'];
    }

    /** Ligne du dossier tel que servi à un rôle (la matière des dashboards). */
    private function lignePour(string $matricule, string $code): ?array
    {
        $requests = $this->actingAs($this->user($matricule), 'sanctum')
            ->getJson('/api/requests')
            ->assertOk()
            ->json('requests');

        return collect($requests)->firstWhere('id', $code);
    }

    /** Le dossier est visible par chaque rôle avec l'étape réelle, égale au statut base. C'est exactement
     * ce que chaque dashboard affiche. */
    private function affirmerEtapePartout(string $code, string $etapeAttendue): void
    {
        foreach ($this->roles() as $matricule) {
            $ligne = $this->lignePour($matricule, $code);
            $this->assertNotNull(
                $ligne,
                "Le dossier {$code} est invisible pour {$matricule} alors qu'il est en {$etapeAttendue}.",
            );
            $this->assertSame(
                $etapeAttendue,
                $ligne['etape'],
                "Le dashboard de {$matricule} affiche {$ligne['etape']} au lieu de {$etapeAttendue} pour {$code}.",
            );
        }
    }

    /* MODULE 1 — DEMANDE DE PERMISSION ( circuit long, > 2 j ) */

    public function test_permission_synchronisee_a_chaque_etape(): void
    {
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions', [
                'typePerm' => 'Événement Familial',
                'dateDebut' => now()->addWeek()->format('Y-m-d'),
                'dateFin' => now()->addWeek()->addDays(4)->format('Y-m-d'),
                'jours' => 5,
                'motif' => 'Événement familial au village.',
            ])->assertCreated()->json('code');
        $id = DemandePermission::where('code_dossier', $code)->value('id');

        // 1. Soumission.
        $this->assertSame('EN_ATTENTE_RH', DemandePermission::find($id)->statut);
        $this->affirmerEtapePartout($code, 'EN_ATTENTE_RH');

        // 2. Contrôle conforme : direct DRH (> 2 j).
        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/permissions/{$id}/verifier", ['decision' => 'conforme'])
            ->assertOk();
        $this->assertSame('EN_ATTENTE_DRH', DemandePermission::find($id)->statut);
        $this->affirmerEtapePartout($code, 'EN_ATTENTE_DRH');

        // 3. Retour du DRH pour correction.
        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson('/api/status', [
                'id' => $code,
                'statut' => 'RETOUR_CORRECTION',
                'motif' => 'Précisez le lien de parenté.',
            ])
            ->assertOk();
        $this->assertSame('RETOUR_CORRECTION', DemandePermission::find($id)->statut);
        $this->affirmerEtapePartout($code, 'RETOUR_CORRECTION');

        // 4. Correction par l'agent : le circuit reprend à la vérification.
        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson("/api/permissions/{$id}/corriger", [
                'date_debut' => now()->addWeek()->format('Y-m-d'),
                'date_fin' => now()->addWeek()->addDays(4)->format('Y-m-d'),
                'motif' => 'Mariage de ma sœur, présence requise.',
            ])
            ->assertOk();
        $this->assertSame('EN_ATTENTE_RH', DemandePermission::find($id)->statut);
        $this->affirmerEtapePartout($code, 'EN_ATTENTE_RH');

        // 5. Nouveau contrôle puis validation finale.
        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/permissions/{$id}/verifier", ['decision' => 'conforme'])
            ->assertOk();
        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson('/api/status', ['id' => $code, 'statut' => 'VALIDEE'])
            ->assertOk();
        $this->assertSame('VALIDEE', DemandePermission::find($id)->statut);
        $this->affirmerEtapePartout($code, 'VALIDEE');

        // 6. La page de détail affiche le même statut réel.
        $detail = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->getJson("/api/permissions/{$id}")
            ->assertOk()
            ->json('data.statut');
        $this->assertSame('VALIDEE', $detail);
    }

    /* MODULE 2 — DÉCLARATION DE NAISSANCE */

    public function test_naissance_synchronisee_a_chaque_etape(): void
    {
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/declarations', [
                'nature' => 'NAISSANCE',
                'nom' => 'Kouadio',
                'prenom' => 'Aya',
                'date' => now()->subMonth()->format('Y-m-d'),
                'lieu' => 'Abidjan',
                'extrait' => UploadedFile::fake()->create('extrait.pdf', 20, 'application/pdf'),
            ])->assertCreated()->json('code');
        $id = DeclarationNaissance::where('code_dossier', $code)->value('id');

        // 1. Soumission.
        $this->assertSame('EN_ATTENTE_RH', DeclarationNaissance::find($id)->statut);
        $this->affirmerEtapePartout($code, 'EN_ATTENTE_RH');

        // 2. Contrôle conforme : direct DRH, sans visa.
        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/naissances/{$id}/controler", ['decision' => 'conforme'])
            ->assertOk();
        $this->assertSame('EN_ATTENTE_DRH', DeclarationNaissance::find($id)->statut);
        $this->affirmerEtapePartout($code, 'EN_ATTENTE_DRH');

        // 3. Validation finale.
        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson('/api/status', ['id' => $code, 'statut' => 'VALIDEE'])
            ->assertOk();
        $this->assertSame('VALIDEE', DeclarationNaissance::find($id)->statut);
        $this->affirmerEtapePartout($code, 'VALIDEE');

        // 4. La page de détail affiche le même statut réel.
        $detail = $this->actingAs($this->user('DRH001'), 'sanctum')
            ->getJson("/api/naissances/{$id}")
            ->assertOk()
            ->json('data.statut');
        $this->assertSame('VALIDEE', $detail);
    }

    /* MODULE 3 — DÉCLARATION DE DÉCÈS */

    public function test_deces_synchronise_a_chaque_etape(): void
    {
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/declarations', [
                'nature' => 'DECES',
                'nom' => 'Yao',
                'prenom' => 'Koffi',
                'lien_parente' => 'ascendant',
                'date' => now()->subMonth()->format('Y-m-d'),
                'lieu' => 'Bouaké',
                'certificat' => UploadedFile::fake()->create('certificat.pdf', 20, 'application/pdf'),
            ])->assertCreated()->json('code');
        $id = DeclarationDeces::where('code_dossier', $code)->value('id');

        // 1. Soumission.
        $this->assertSame('EN_ATTENTE_RH', DeclarationDeces::find($id)->statut);
        $this->affirmerEtapePartout($code, 'EN_ATTENTE_RH');

        // 2. Contrôle conforme : direct DRH.
        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/deces/{$id}/controler", ['decision' => 'conforme'])
            ->assertOk();
        $this->assertSame('EN_ATTENTE_DRH', DeclarationDeces::find($id)->statut);
        $this->affirmerEtapePartout($code, 'EN_ATTENTE_DRH');

        // 3. Retour du DRH puis correction : le statut reste synchrone partout.
        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson('/api/status', [
                'id' => $code,
                'statut' => 'RETOUR_CORRECTION',
                'motif' => 'Certificat illisible.',
            ])
            ->assertOk();
        $this->assertSame('RETOUR_CORRECTION', DeclarationDeces::find($id)->statut);
        $this->affirmerEtapePartout($code, 'RETOUR_CORRECTION');

        // 4. La page de détail affiche le même statut réel.
        $detail = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->getJson("/api/deces/{$id}")
            ->assertOk()
            ->json('data.statut');
        $this->assertSame('RETOUR_CORRECTION', $detail);
    }

    /* TRAÇABILITÉ D'AFFICHAGE : « TRAITÉ PAR MOI » */

    /** Un dossier transmis par le gestionnaire reste marqué pour lui : son dashboard le garde visible
     * avec son statut réel. */
    public function test_le_gestionnaire_revoit_ses_dossiers_transmis(): void
    {
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions', [
                'typePerm' => 'Événement Familial',
                'dateDebut' => now()->addWeek()->format('Y-m-d'),
                'dateFin' => now()->addWeek()->addDays(4)->format('Y-m-d'),
                'jours' => 5,
                'motif' => 'Suivi après transmission.',
            ])->assertCreated()->json('code');
        $id = DemandePermission::where('code_dossier', $code)->value('id');

        $ligneAvant = $this->lignePour('RH001', $code);
        $this->assertFalse($ligneAvant['traite_par_moi']);

        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/permissions/{$id}/verifier", ['decision' => 'conforme'])
            ->assertOk();

        $ligneApres = $this->lignePour('RH001', $code);
        $this->assertTrue($ligneApres['traite_par_moi']);
        $this->assertSame('EN_ATTENTE_DRH', $ligneApres['etape']);

        // Les autres rôles ne sont pas marqués.
        $ligneAgent = $this->lignePour('AGT001', $code);
        $this->assertFalse($ligneAgent['traite_par_moi']);
    }
}
