<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\DeclarationNaissance;
use App\Models\DemandePermission;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Un sous-directeur ou un directeur dépose comme un agent, mais son dossier va directement au DRH : il
 * ne peut pas se déclarer conforme lui-même. */
class DepotResponsableTest extends TestCase
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

    /** Jeu de champs d'une permission, commun à tous les scénarios. */
    private function permission(array $surcharge = []): array
    {
        return array_merge([
            'typePerm' => 'Événement Familial',
            'dateDebut' => now()->addWeek()->format('Y-m-d'),
            'dateFin' => now()->addWeek()->addDay()->format('Y-m-d'),
            'jours' => 2,
            'motif' => 'Événement familial au village.',
        ], $surcharge);
    }

    /** Jeu de champs d'une déclaration de naissance. */
    private function naissance(array $surcharge = []): array
    {
        return array_merge([
            'nature' => 'NAISSANCE',
            'nom' => 'Kouadio',
            'prenom' => 'Aya',
            'date' => now()->subMonth()->format('Y-m-d'),
            'lieu' => 'Abidjan',
        ], $surcharge);
    }

    /* IDENTIFICATION DU PROFIL */

    public function test_le_responsable_est_reconnu_comme_autorite_de_visa(): void
    {
        $this->assertTrue($this->user('DIR001')->agent->autoriteVisa());
        $this->assertSame('DIRECTEUR', $this->user('DIR001')->agent->roleVisa());
        $this->assertTrue($this->user('SD001')->agent->autoriteVisa());
        $this->assertSame('SOUS_DIRECTEUR', $this->user('SD001')->agent->roleVisa());
    }

    public function test_un_agent_simple_nest_pas_autorite_de_visa(): void
    {
        $this->assertFalse($this->user('AGT001')->agent->autoriteVisa());
        $this->assertNull($this->user('AGT001')->agent->roleVisa());
        $this->assertFalse($this->user('RH001')->agent->autoriteVisa());
        $this->assertFalse($this->user('DRH001')->agent->autoriteVisa());
    }

    /* PERMISSION : DIRECTION VERS LE DRH */

    public function test_la_permission_du_directeur_va_directement_au_drh(): void
    {
        $res = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/permissions', $this->permission());

        $res->assertCreated();
        $this->assertSame(
            DemandePermission::EN_ATTENTE_DRH,
            $res->json('statut'),
            "Un directeur ne peut pas attendre son propre visa : le dossier va au DRH.",
        );
    }

    public function test_la_permission_du_sous_directeur_va_directement_au_drh(): void
    {
        $res = $this->actingAs($this->user('SD001'), 'sanctum')
            ->postJson('/api/permissions', $this->permission());

        $res->assertCreated();
        $this->assertSame(DemandePermission::EN_ATTENTE_DRH, $res->json('statut'));
    }

    /** Le gestionnaire RH n'a rien à vérifier là-dessus : son circuit est inchangé. */
    public function test_le_gestionnaire_rh_nest_pas_bouleverse_par_ce_routage(): void
    {
        $res = $this->actingAs($this->user('RH001'), 'sanctum')
            ->postJson('/api/permissions', $this->permission());

        $res->assertCreated();
        $this->assertSame(DemandePermission::EN_ATTENTE_RH, $res->json('statut'));
    }

    public function test_le_directeur_est_prevenu_que_sa_demande_est_chez_le_drh(): void
    {
        $res = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/permissions', $this->permission());

        $this->assertDatabaseHas('notifications', [
            'agent_id' => $this->user('DRH001')->agent_id,
            'reference_dossier' => $res->json('code'),
            'type' => 'ATTENTE_DRH',
        ]);
    }

    /** Le journal d'audit retrace le motif du saut d'étage. */
    public function test_le_saut_au_drh_est_trace_dans_l_historique(): void
    {
        $res = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/permissions', $this->permission());

        $this->assertDatabaseHas('demande_historique', [
            'demande_id' => DemandePermission::where('code_dossier', $res->json('code'))->value('id'),
            'nouveau_statut' => DemandePermission::EN_ATTENTE_DRH,
        ]);
    }

    /* BROUILLON : LE MÊME CIRCUIT UNE FOIS SOUMIS */

    public function test_le_brouillon_du_directeur_soumis_va_directement_au_drh(): void
    {
        $draft = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/permissions/brouillon', $this->permission());
        $draft->assertCreated();
        $code = $draft->json('code');

        $this->assertDatabaseHas('demandes_permission', [
            'code_dossier' => $code,
            'statut' => DemandePermission::BROUILLON,
        ]);

        $res = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/permissions/soumettre-brouillon', ['code_dossier' => $code]);

        $res->assertOk();
        $this->assertSame(DemandePermission::EN_ATTENTE_DRH, $res->json('statut'));
    }

    /* NAISSANCE : DIRECTION VERS LE DRH */

    public function test_la_naissance_du_directeur_va_directement_au_drh(): void
    {
        $res = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/declarations', $this->naissance([
                'extrait' => UploadedFile::fake()->create('extrait.pdf', 20, 'application/pdf'),
            ]));

        $res->assertCreated();
        $this->assertSame('EN_ATTENTE_DRH', $res->json('statut'));
    }

    public function test_la_naissance_du_sous_directeur_va_directement_au_drh(): void
    {
        $res = $this->actingAs($this->user('SD001'), 'sanctum')
            ->postJson('/api/declarations', $this->naissance([
                'extrait' => UploadedFile::fake()->create('extrait.pdf', 20, 'application/pdf'),
            ]));

        $res->assertCreated();
        $this->assertSame('EN_ATTENTE_DRH', $res->json('statut'));
    }

    /** Le gestionnaire RH continue de contrôler les déclarations des agents. */
    public function test_la_naissance_de_l_agent_simple_passe_par_le_gestionnaire_rh(): void
    {
        $res = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/declarations', $this->naissance([
                'extrait' => UploadedFile::fake()->create('extrait.pdf', 20, 'application/pdf'),
            ]));

        $res->assertCreated();
        $this->assertSame('EN_ATTENTE_RH', $res->json('statut'));
    }

    public function test_le_brouillon_de_naissance_du_directeur_soumis_va_au_drh(): void
    {
        $draft = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/declarations/brouillon', $this->naissance([
                'extrait' => UploadedFile::fake()->create('extrait.pdf', 20, 'application/pdf'),
            ]));
        $draft->assertCreated();
        $code = $draft->json('code');

        $this->assertDatabaseHas('declarations_naissance', [
            'code_dossier' => $code,
            'statut' => 'BROUILLON',
        ]);

        $res = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/declarations/soumettre-brouillon', [
                'nature' => 'NAISSANCE',
                'code_dossier' => $code,
            ]);

        $res->assertOk();
        $this->assertSame('EN_ATTENTE_DRH', $res->json('statut'));
    }

    /* PAS D'ENVOI EN DOUBLE */

    /** Un responsable ne peut pas se viser lui-même par l'entremise du gestionnaire RH. */
    public function test_le_gestionnaire_rh_ne_retrouve_pas_la_demande_du_directeur_dans_sa_boite(): void
    {
        $res = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/permissions', $this->permission());
        $code = $res->json('code');

        $this->assertDatabaseMissing('demandes_permission', [
            'code_dossier' => $code,
            'statut' => DemandePermission::EN_ATTENTE_RH,
        ]);
    }

    /** Et il ne peut pas la traiter par un raccourci : le dossier n'est plus dans sa boîte. */
    public function test_le_directeur_ne_peut_pas_viser_sa_propre_demande(): void
    {
        $res = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/permissions', $this->permission());
        $dossierId = DemandePermission::where('code_dossier', $res->json('code'))->value('id');

        $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson("/api/permissions/{$dossierId}/verifier", ['decision' => 'transmettre'])
            ->assertForbidden();
    }

    /* VÉRIFICATION DANS LE NAVIGATEUR : LE RESPONSABLE VOIT SES DOSSIERS */

    public function test_le_directeur_voit_ses_requetes_parmi_toutes_celles_du_circuit(): void
    {
        $mien = $this->actingAs($this->user('DIR001'), 'sanctum')
            ->postJson('/api/permissions', $this->permission())
            ->json('code');
        $autre = $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions', $this->permission())
            ->json('code');

        $res = $this->actingAs($this->user('DIR001'), 'sanctum')->getJson('/api/requests');
        $res->assertOk();

        $codes = array_column($res->json('requests'), 'id');
        $this->assertContains($mien, $codes);
        $this->assertContains($autre, $codes, 'Le directeur suit aussi les dossiers des agents.');

        // C'est le filtre par matricule côté interface qui isole « mes demandes ».
        $mienRow = collect($res->json('requests'))->firstWhere('id', $mien);
        $this->assertSame('DIR001', $mienRow['matricule']);
    }
}
