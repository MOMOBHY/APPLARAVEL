<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationPeriodePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
    }

    private function demande(array $surcharge = []): array
    {
        return array_merge([
            'typePerm' => 'Événement Familial',
            'motif' => 'Motif de test valide',
            'dateDebut' => '2026-11-02',
            'dateFin' => '2026-11-03',
            'jours' => 2,
        ], $surcharge);
    }

    public function test_refuse_une_date_de_fin_anterieure_au_debut_avec_un_message_francais(): void
    {
        $response = $this->actingAs(User::where('matricule', 'AGT001')->firstOrFail(), 'sanctum')
            ->postJson('/api/permissions', $this->demande(['dateFin' => '2026-11-01']));

        $response->assertUnprocessable()
            ->assertJsonPath(
                'errors.dateFin.0',
                'La date de fin doit être postérieure ou égale à la date de début.',
            );
    }

    public function test_refuse_une_periode_superieure_a_trente_jours(): void
    {
        $agent = User::where('matricule', 'AGT001')->firstOrFail();
        $response = $this->actingAs($agent, 'sanctum')
            ->postJson('/api/permissions', $this->demande([
                'dateDebut' => '2026-11-01',
                'dateFin' => '2026-12-01',
                'jours' => 1,
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Une permission ne peut pas dépasser 30 jours.');

        $this->actingAs($agent, 'sanctum')
            ->postJson('/api/permissions', $this->demande(['jours' => 31]))
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.jours.0',
                'Une permission ne peut pas dépasser 30 jours.',
            );
    }

    public function test_accepte_une_periode_valide_et_calcule_sa_duree(): void
    {
        $response = $this->actingAs(User::where('matricule', 'AGT001')->firstOrFail(), 'sanctum')
            ->postJson('/api/permissions', $this->demande());

        $response->assertCreated()
            ->assertJsonPath('jours', 2);
    }
}
