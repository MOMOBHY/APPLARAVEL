<?php

namespace Tests\Feature;

use App\Models\DeclarationNaissance;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Une déclaration de naissance contrôlée conforme part directement à la DRH, comme un décès : aucun visa
 * intermédiaire ne la retient. Le DRH la reçoit dans ses dossiers et sur son tableau de bord. */
class VisaNaissanceTest extends TestCase
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

    /** Naissance déclarée par l'agent puis contrôlée conforme : chez la DRH. */
    private function naissanceControlee(): string
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
        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/naissances/{$id}/controler", ['decision' => 'conforme'])
            ->assertOk();

        return $code;
    }

    public function test_la_naissance_controlee_va_directement_au_drh(): void
    {
        $code = $this->naissanceControlee();

        $this->assertDatabaseHas('declarations_naissance', [
            'code_dossier' => $code,
            'statut' => 'EN_ATTENTE_DRH',
        ]);
        $this->assertDatabaseHas('notifications', [
            'agent_id' => $this->user('DRH001')->agent_id,
            'reference_dossier' => $code,
            'type' => 'ATTENTE_DRH',
        ]);
    }

    public function test_le_drh_recoit_la_naissance_dans_ses_dossiers(): void
    {
        $code = $this->naissanceControlee();

        $ligne = collect($this->actingAs($this->user('DRH001'), 'sanctum')
            ->getJson('/api/requests')->assertOk()->json('requests'))
            ->firstWhere('id', $code);

        $this->assertNotNull($ligne, 'Le DRH ne reçoit pas la naissance contrôlée.');
        $this->assertSame('EN_ATTENTE_DRH', $ligne['etape']);
    }

    public function test_le_drh_tranche_la_naissance_recue(): void
    {
        $code = $this->naissanceControlee();

        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson('/api/status', ['id' => $code, 'statut' => 'VALIDEE'])
            ->assertOk();

        $this->assertDatabaseHas('declarations_naissance', [
            'code_dossier' => $code,
            'statut' => 'VALIDEE',
        ]);
    }

    public function test_plus_de_route_de_visa_pour_les_naissances(): void
    {
        $code = $this->naissanceControlee();
        $id = DeclarationNaissance::where('code_dossier', $code)->value('id');

        $this->actingAs($this->user('SD001'), 'sanctum')
            ->postJson("/api/naissances/{$id}/viser", ['valide' => true])
            ->assertNotFound();
    }

    public function test_un_agent_ne_peut_pas_controler_une_naissance(): void
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

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson("/api/naissances/{$id}/controler", ['decision' => 'conforme'])
            ->assertForbidden();
    }
}
