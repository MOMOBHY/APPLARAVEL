<?php

namespace Tests\Feature;

use App\Mail\CodeReinitialisation;
use App\Models\DeclarationNaissance;
use App\Models\DemandePermission;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Non-régression : justificatif joint en modifiant un brouillon, brouillon incomplet soumis,
 * auto-suspension de l'administrateur, code de réinitialisation expiré. */
class CorrectionsBrouillonsEtComptesTest extends TestCase
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

    public function test_l_extrait_joint_en_modifiant_le_brouillon_permet_de_le_soumettre(): void
    {
        $agent = $this->user('AGT001');
        $code = $this->actingAs($agent, 'sanctum')
            ->postJson('/api/declarations/brouillon', [
                'nature' => 'NAISSANCE',
                'nom' => 'KOUASSI',
                'prenom' => 'Yann',
                'date' => '2026-08-20',
                'lieu' => 'Abidjan',
            ])
            ->assertCreated()
            ->json('code');

        // Sans pièce, la soumission est refusée.
        $this->postJson('/api/declarations/soumettre-brouillon', ['nature' => 'NAISSANCE', 'code_dossier' => $code])
            ->assertUnprocessable();

        // L'agent reprend son brouillon et joint l'extrait.
        $this->postJson('/api/declarations/brouillon', [
            'nature' => 'NAISSANCE',
            'code_dossier' => $code,
            'extrait' => UploadedFile::fake()->create('extrait.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $this->assertNotNull(DeclarationNaissance::where('code_dossier', $code)->value('extrait_path'));
        $this->postJson('/api/declarations/soumettre-brouillon', ['nature' => 'NAISSANCE', 'code_dossier' => $code])
            ->assertOk()
            ->assertJson(['status' => 'success']);
    }

    public function test_un_brouillon_de_declaration_incomplet_ne_peut_pas_etre_soumis(): void
    {
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/declarations/brouillon', [
                'nature' => 'NAISSANCE',
                'extrait' => UploadedFile::fake()->create('extrait.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('code');

        $this->postJson('/api/declarations/soumettre-brouillon', ['nature' => 'NAISSANCE', 'code_dossier' => $code])
            ->assertUnprocessable();
        $this->assertSame('BROUILLON', DeclarationNaissance::where('code_dossier', $code)->value('statut'));
    }

    public function test_un_brouillon_de_permission_sans_motif_ne_peut_pas_etre_soumis(): void
    {
        $code = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions/brouillon', [
                'typePerm' => 'Événement Familial',
                'dateDebut' => '2026-10-01',
                'dateFin' => '2026-10-02',
            ])
            ->assertCreated()
            ->json('code');

        $this->postJson('/api/permissions/soumettre-brouillon', ['code_dossier' => $code])
            ->assertUnprocessable();
        $this->assertSame('BROUILLON', DemandePermission::where('code_dossier', $code)->value('statut'));
    }

    public function test_l_administrateur_ne_peut_pas_se_suspendre_avec_une_valeur_texte(): void
    {
        $admin = $this->user('ADM001');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users/update', ['matricule' => 'ADM001', 'actif' => '0'])
            ->assertUnprocessable();

        $this->assertTrue($admin->fresh()->actif);
    }

    public function test_un_code_de_reinitialisation_expire_est_refuse(): void
    {
        Mail::fake();
        $this->postJson('/api/mot-de-passe/demande', ['matricule' => 'AGT001'])->assertCreated();

        $code = null;
        Mail::assertSent(CodeReinitialisation::class, function (CodeReinitialisation $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->travel(61)->minutes();

        $this->postJson('/api/mot-de-passe/reinitialiser', [
            'matricule' => 'AGT001',
            'code' => $code,
            'password' => 'nouveau-mdp',
            'password_confirmation' => 'nouveau-mdp',
        ])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Ce code a expiré : faites une nouvelle demande.']);
    }
}
