<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructuresMinistereTest extends TestCase
{
    use RefreshDatabase;

    public function test_annuaire_classe_de_a_a_z_avec_rattachements_sans_les_structures_de_demonstration(): void
    {
        $this->seed(GfpSeeder::class);
        $this->getJson('/api/annuaire-structures')->assertUnauthorized();

        $reponse = $this->actingAs(User::where('matricule', 'ADM001')->firstOrFail(), 'sanctum')
            ->getJson('/api/annuaire-structures')->assertOk();

        $noms = collect($reponse->json('structures'))->pluck('nom');
        $this->assertTrue($noms->contains('Direction Générale de la Fonction Publique'));
        $this->assertTrue($noms->contains('Direction des Concours'));
        $this->assertFalse($noms->contains('Service des Études'));
        $this->assertSame('Cabinet du Ministre', $noms->first());

        $dc = collect($reponse->json('structures'))->firstWhere('code', 'DC');
        $this->assertSame('DGFP', $dc['rattachement_sigle']);
        $this->assertSame($reponse->json('total'), $noms->count());
    }
}
