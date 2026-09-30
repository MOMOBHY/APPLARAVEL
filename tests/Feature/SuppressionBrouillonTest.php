<?php

namespace Tests\Feature;

use App\Models\DeclarationHistorique;
use App\Models\PieceJointe;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Suppression d'un brouillon par son auteur : le dossier part, et avec lui ses pièces sur le disque, ses
 * lignes d'historique et sa trace d'audit. */
class SuppressionBrouillonTest extends TestCase
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

    /** Crée un brouillon de permission avec sa pièce et renvoie son code. */
    private function brouillonPermission(string $matricule = 'AGT001', string $motif = 'Mariage'): string
    {
        return $this->actingAs($this->user($matricule), 'sanctum')
            ->postJson('/api/permissions/brouillon', [
                'typePerm' => 'Événement Familial',
                'motif' => $motif,
                'dateDebut' => '2026-10-01',
                'dateFin' => '2026-10-03',
                'piece' => UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('code');
    }

    /** Crée un brouillon de naissance avec son extrait et renvoie son code. */
    private function brouillonNaissance(string $matricule = 'AGT001'): string
    {
        return $this->actingAs($this->user($matricule), 'sanctum')
            ->postJson('/api/declarations/brouillon', [
                'nature' => 'NAISSANCE',
                'nom' => 'KOUASSI',
                'prenom' => 'Yann',
                'date' => '2026-08-20',
                'lieu' => 'Abidjan',
                'extrait' => UploadedFile::fake()->create('extrait.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('code');
    }

    public function test_supprime_un_brouillon_de_permission_avec_sa_piece_sur_le_disque(): void
    {
        $code = $this->brouillonPermission();

        $this->assertDatabaseHas('demandes_permission', ['code_dossier' => $code, 'statut' => 'BROUILLON']);
        $this->assertSame(1, PieceJointe::where('reference_dossier', $code)->count());

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/brouillons/supprimer', ['code_dossier' => $code])
            ->assertOk()
            ->assertJson(['status' => 'success', 'code' => $code]);

        $this->assertDatabaseMissing('demandes_permission', ['code_dossier' => $code]);
        $this->assertSame(0, PieceJointe::where('reference_dossier', $code)->count());
        // La fiche est effacée, donc son fichier ne doit plus exister non plus.
        $this->assertEmpty(Storage::disk('local')->allFiles('pieces'));
    }

    public function test_supprime_un_brouillon_de_declaration_et_son_historique_sans_cle_etrangere(): void
    {
        $code = $this->brouillonNaissance();

        // declaration_historique n'a pas de clé étrangère : c'est ce qui risque de rester.
        $this->assertGreaterThan(0, DeclarationHistorique::where('code_dossier', $code)->count());

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/brouillons/supprimer', ['code_dossier' => $code])
            ->assertOk();

        $this->assertDatabaseMissing('declarations_naissance', ['code_dossier' => $code]);
        $this->assertSame(0, DeclarationHistorique::where('code_dossier', $code)->count());
        $this->assertSame(0, PieceJointe::where('reference_dossier', $code)->count());
        $this->assertEmpty(Storage::disk('local')->allFiles('pieces'));
    }

    public function test_un_agent_ne_peut_pas_supprimer_le_brouillon_d_un_autre(): void
    {
        $code = $this->brouillonPermission('AGT001');

        // SD001 est bien authentifié, mais le brouillon appartient à AGT001.
        $this->actingAs($this->user('SD001'), 'sanctum')
            ->postJson('/api/brouillons/supprimer', ['code_dossier' => $code])
            ->assertForbidden();

        $this->assertDatabaseHas('demandes_permission', ['code_dossier' => $code]);
    }

    public function test_un_dossier_deja_soumis_ne_peut_pas_etre_supprime(): void
    {
        $code = $this->brouillonPermission();

        // On le sort du brouillon pour vérifier que la porte se referme.
        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions/soumettre-brouillon', ['code_dossier' => $code])
            ->assertOk();
        $this->assertDatabaseHas('demandes_permission', ['code_dossier' => $code, 'statut' => 'EN_ATTENTE_RH']);

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/brouillons/supprimer', ['code_dossier' => $code])
            ->assertForbidden()
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('demandes_permission', ['code_dossier' => $code]);
    }

    public function test_un_code_inconnu_repond_404_et_une_annee_vide_est_refusee(): void
    {
        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/brouillons/supprimer', ['code_dossier' => 'PERM-2026-XXXXXX'])
            ->assertNotFound();

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/brouillons/supprimer', [])
            ->assertStatus(422);
    }

    public function test_la_suppression_est_tracee_dans_le_journal_d_audit(): void
    {
        $code = $this->brouillonPermission();

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/brouillons/supprimer', ['code_dossier' => $code])
            ->assertOk();

        $this->assertDatabaseHas('journal_audit', [
            'action' => 'suppression_brouillon',
            'reference' => $code,
        ]);
    }

    public function test_le_brouillon_supprime_n_apparait_plus_dans_l_historique_de_l_agent(): void
    {
        $code = $this->brouillonPermission();

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/brouillons/supprimer', ['code_dossier' => $code])
            ->assertOk();

        $codes = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->getJson('/api/requests')
            ->assertOk()
            ->json('requests.*.id');

        $this->assertNotContains($code, $codes);
    }

    public function test_un_brouillon_de_deces_se_supprime_egalement(): void
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
            ])
            ->assertCreated()
            ->json('code');

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/brouillons/supprimer', ['code_dossier' => $code])
            ->assertOk();

        $this->assertDatabaseMissing('declarations_deces', ['code_dossier' => $code]);
        $this->assertSame(0, DeclarationHistorique::where('type_dossier', 'DECES')->count());
        $this->assertEmpty(Storage::disk('local')->allFiles('pieces'));
    }
}
