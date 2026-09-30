<?php

namespace Tests\Feature;

use App\Models\DemandePermission;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Le DRH ne rejette plus : il valide, ou il retourne pour correction avec un motif. Le dossier repart en
 * RETOUR_CORRECTION, l'agent le corrige puis le resoumet, et le circuit reprend à la vérification RH. */
class RetourDrhTest extends TestCase
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

    /** Permission de 5 jours : contrôle RH puis décision DRH directe. */
    private function permissionADecider(): string
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
        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/permissions/{$id}/verifier", ['decision' => 'conforme'])
            ->assertOk();

        return $code;
    }

    public function test_le_drh_retourne_une_permission_pour_correction(): void
    {
        $code = $this->permissionADecider();

        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson('/api/status', [
                'id' => $code,
                'statut' => 'RETOUR_CORRECTION',
                'motif' => 'Précisez le lien avec l’événement.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('demandes_permission', [
            'code_dossier' => $code,
            'statut' => DemandePermission::RETOUR_CORRECTION,
            'motif_retour' => 'Précisez le lien avec l’événement.',
        ]);
        // L'agent est notifié directement, sans relais.
        $this->assertDatabaseHas('notifications', [
            'reference_dossier' => $code,
            'type' => 'RETOUR_CORRECTION',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'reference_dossier' => $code,
            'type' => 'A_NOTIFIER',
        ]);
    }

    public function test_l_agent_corrige_apres_un_retour_du_drh_et_le_circuit_reprend(): void
    {
        $code = $this->permissionADecider();
        $id = DemandePermission::where('code_dossier', $code)->value('id');

        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson('/api/status', [
                'id' => $code,
                'statut' => 'RETOUR_CORRECTION',
                'motif' => 'Motif à détailler.',
            ])
            ->assertOk();

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson("/api/permissions/{$id}/corriger", [
                'date_debut' => now()->addWeek()->format('Y-m-d'),
                'date_fin' => now()->addWeek()->addDays(4)->format('Y-m-d'),
                'motif' => 'Mariage de ma sœur au village, présence requise.',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', DemandePermission::EN_ATTENTE_RH);
    }

    public function test_le_retour_du_drh_exige_un_motif(): void
    {
        $code = $this->permissionADecider();

        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson('/api/status', ['id' => $code, 'statut' => 'RETOUR_CORRECTION'])
            ->assertStatus(422);
    }

    public function test_seul_le_drh_peut_retourner(): void
    {
        $code = $this->permissionADecider();

        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson('/api/status', [
                'id' => $code,
                'statut' => 'RETOUR_CORRECTION',
                'motif' => 'Tentative du gestionnaire.',
            ])
            ->assertForbidden();
    }

    public function test_le_drh_retourne_une_declaration_de_deces(): void
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

        $id = \App\Models\DeclarationDeces::where('code_dossier', $code)->value('id');
        $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson("/api/deces/{$id}/controler", ['decision' => 'conforme'])
            ->assertOk();

        $this->actingAs($this->user('DRH001'), 'sanctum')
            ->postJson('/api/status', [
                'id' => $code,
                'statut' => 'RETOUR_CORRECTION',
                'motif' => 'Certificat illisible, renvoyez une copie nette.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('declarations_deces', [
            'code_dossier' => $code,
            'statut' => 'RETOUR_CORRECTION',
        ]);

        // L'agent corrige et le circuit reprend à la vérification RH.
        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson("/api/deces/{$id}/corriger", [
                'nom_defunt' => 'YAO',
                'prenom_defunt' => 'Koffi',
                'lien_parente' => 'ascendant',
                'date_deces' => now()->subMonth()->format('Y-m-d'),
                'lieu_deces' => 'Bouaké',
                'certificat' => UploadedFile::fake()->create('certificat2.pdf', 20, 'application/pdf'),
            ])
            ->assertOk();
        $this->assertDatabaseHas('declarations_deces', [
            'code_dossier' => $code,
            'statut' => 'EN_ATTENTE_RH',
        ]);
    }
}
