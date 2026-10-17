<?php

namespace Tests\Feature;

use App\Models\DemandePermission;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Une pièce jointe qui n'arrive pas entière (trop lourde pour PHP, envoi interrompu) fait refuser le
 * dépôt avec un message, au lieu d'enregistrer le dossier sans son justificatif. */
class PiecesJointesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
    }

    private function payloadPermission(): array
    {
        return [
            'typePerm' => 'Événement Familial',
            'motif' => 'Démarche personnelle',
            'dateDebut' => '2026-10-01',
            'dateFin' => '2026-10-02',
            'jours' => 2,
        ];
    }

    public function test_une_piece_non_recue_fait_refuser_le_depot(): void
    {
        $piece = new UploadedFile(__FILE__, 'photo.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->actingAs(User::where('matricule', 'AGT001')->firstOrFail(), 'sanctum')
            ->postJson('/api/permissions', $this->payloadPermission() + ['piece' => $piece])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, DemandePermission::count());
    }

    public function test_une_piece_recue_est_enregistree(): void
    {
        $piece = UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf');

        $this->actingAs(User::where('matricule', 'AGT001')->firstOrFail(), 'sanctum')
            ->postJson('/api/permissions', $this->payloadPermission() + ['piece' => $piece])
            ->assertCreated();

        $this->assertNotNull(DemandePermission::firstOrFail()->piece_path);
    }
}
