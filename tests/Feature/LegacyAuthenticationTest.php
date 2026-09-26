<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Structure;
use App\Models\TypePermission;
use App\Models\User;
use App\Services\PermissionWorkflowService;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegacyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
    }

    public function test_directeur_central_ne_peut_pas_utiliser_le_profil_directeur_de_cabinet(): void
    {
        $this->postJson('/api/login', [
            'matricule' => 'DIR001',
            'password' => 'test123',
            'role' => 'DIRCAB',
        ])->assertForbidden();

        $this->postJson('/api/login', [
            'matricule' => 'CAB001',
            'password' => 'test123',
            'role' => 'DIRCAB',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_inscription_avec_choix_du_role_puis_connexion(): void
    {
        $this->postJson('/api/register', [
            'civilite' => 'Mme',
            'nom' => 'Kouassi',
            'prenom' => 'Aminata',
            'matricule' => 'TEST-RH-001',
            'password' => 'motdepasse',
            'role' => 'ROLE_CHEF_SERVICE',
            'structure_id' => Structure::where('code', 'DRH')->value('id'),
        ])->assertCreated();

        // Connexion avec les seuls identifiants : le profil est déduit du rôle choisi.
        $this->postJson('/api/login', ['matricule' => 'test-rh-001', 'password' => 'motdepasse'])
            ->assertOk()
            ->assertJsonPath('user.role', 'RESPONSABLE');

        // L'administration n'est jamais auto-attribuée.
        $this->postJson('/api/register', [
            'civilite' => 'M.',
            'nom' => 'Pirate',
            'prenom' => 'Test',
            'matricule' => 'ADMIN-X',
            'password' => 'motdepasse',
            'role' => 'ROLE_ADMIN_DSI',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['matricule' => 'ADMIN-X']);
    }

    public function test_administrateur_peut_attribuer_un_role_gestionnaire_sans_role_sous_directeur(): void
    {
        $admin = User::where('matricule', 'ADM001')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'civilite' => 'Mme',
                'nom' => 'Kouassi',
                'prenom' => 'Aminata',
                'matricule' => 'TEST-RH-001',
                'password' => 'motdepasse',
                'role' => 'ROLE_CHEF_SERVICE',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'success');

        $roles = User::where('matricule', 'TEST-RH-001')->firstOrFail()->roles->pluck('code');

        $this->assertTrue($roles->contains('ROLE_GESTIONNAIRE_RH'));
        $this->assertFalse($roles->contains('ROLE_SOUS_DIRECTEUR'));
    }

    public function test_agent_ne_voit_pas_les_demandes_d_un_autre_agent(): void
    {
        $demande = PermissionWorkflowService::soumettre(
            User::where('matricule', 'SVC001')->firstOrFail()->agent,
            [
                'type_permission_id' => TypePermission::firstOrFail()->id,
                'date_debut' => '2026-10-01',
                'date_fin' => '2026-10-01',
                'motif' => 'Demande confidentielle',
            ],
        );

        $agent = User::where('matricule', 'AGT001')->firstOrFail();

        $this->actingAs($agent, 'sanctum')
            ->getJson('/api/requests')
            ->assertOk()
            ->assertJsonMissing(['id' => $demande->code_dossier]);
    }

    public function test_agent_ne_peut_pas_consulter_ni_marquer_les_notifications_d_un_autre_agent(): void
    {
        $destinataire = User::where('matricule', 'SVC001')->firstOrFail();
        $notification = Notification::create([
            'agent_id' => $destinataire->agent_id,
            'titre' => 'Notification privée',
            'message' => 'Cette notification ne doit pas être exposée.',
            'type' => 'INFO',
            'reference_dossier' => 'PRIVE-001',
        ]);
        $agent = User::where('matricule', 'AGT001')->firstOrFail();

        $this->actingAs($agent, 'sanctum')
            ->getJson('/api/notifications?agent_id='.$destinataire->agent_id)
            ->assertOk()
            ->assertJsonMissing(['id_notification' => $notification->id]);

        $this->actingAs($agent, 'sanctum')
            ->postJson('/api/notifications/read', [
                'agent_id' => $destinataire->agent_id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'est_lu' => false]);
    }

    public function test_connexion_enregistre_la_derniere_connexion(): void
    {
        $this->postJson('/api/login', [
            'matricule' => 'AGT001',
            'password' => 'test123',
        ])->assertOk();

        $this->assertNotNull(User::where('matricule', 'AGT001')->firstOrFail()->derniere_connexion);
    }

    public function test_connexion_blade_ouvre_une_session_web(): void
    {
        $this->from('/login')
            ->post('/login', ['matricule' => 'AGT001', 'password' => 'mauvais'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('matricule');
        $this->assertGuest('web');

        $this->post('/login', ['matricule' => 'agt001', 'password' => 'test123'])->assertRedirect(
            route('dashboard'),
        );
        $this->assertAuthenticatedAs(User::where('matricule', 'AGT001')->firstOrFail(), 'web');

        $this->get('/dashboard')->assertOk()->assertSee('Espace agent');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_deconnexion_web_ferme_la_session(): void
    {
        $this->actingAs(User::where('matricule', 'DRH001')->firstOrFail(), 'web')
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_deconnexion_api_revoque_le_jeton(): void
    {
        $token = $this->postJson('/api/login', [
            'matricule' => 'AGT001',
            'password' => 'test123',
        ])->json('token');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_tentatives_de_connexion_limitees(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'matricule' => 'AGT001',
                'password' => 'mauvais',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'matricule' => 'AGT001',
            'password' => 'test123',
        ])->assertTooManyRequests();
    }

    public function test_changement_de_mot_de_passe_sans_role_conserve_les_roles(): void
    {
        $admin = User::where('matricule', 'ADM001')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users/update', [
                'matricule' => 'SEC001',
                'password' => 'nouveau123',
            ])
            ->assertOk();

        $secretaire = User::where('matricule', 'SEC001')->firstOrFail();
        $this->assertTrue(Hash::check('nouveau123', $secretaire->password));
        $this->assertEquals(['ROLE_SECRETAIRE'], $secretaire->roles->pluck('code')->all());
    }

    public function test_profil_responsable_attribue_uniquement_gestionnaire_rh(): void
    {
        $admin = User::where('matricule', 'ADM001')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users/update', [
                'matricule' => 'AGT001',
                'role' => 'RESPONSABLE',
            ])
            ->assertOk();

        $this->assertEquals(
            ['ROLE_GESTIONNAIRE_RH'],
            User::where('matricule', 'AGT001')->firstOrFail()->roles->pluck('code')->all(),
        );
    }

    public function test_creation_d_un_homonyme_refusee_sans_erreur_serveur(): void
    {
        $admin = User::where('matricule', 'ADM001')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'matricule' => 'NOUVEAU01',
                'nom' => 'Gramboute',
                'prenom' => 'Mohamed',
                'password' => 'motdepasse',
                'role' => 'ROLE_AGENT',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cette personne est déjà enregistrée.');
    }

    public function test_liste_des_utilisateurs_expose_le_role_reel(): void
    {
        $admin = User::where('matricule', 'ADM001')->firstOrFail();

        $users = collect(
            $this->actingAs($admin, 'sanctum')->getJson('/api/users')->assertOk()->json('users'),
        )->keyBy('matricule');

        $this->assertEquals('ROLE_CHEF_DE_SERVICE', $users['CHEF001']['role']);
        $this->assertEquals('ROLE_DIRCAB', $users['CAB001']['role']);
    }

    /** Mot de passe oublié : demande, attente de l'administrateur, autorisation puis nouveau mot de passe. */
    public function test_mot_de_passe_oublie_autorise_par_l_administrateur(): void
    {
        $code = $this->postJson('/api/mot-de-passe/demande', ['matricule' => 'agt001'])
            ->assertCreated()
            ->json('code');
        $reinitialisation = [
            'matricule' => 'AGT001',
            'code' => $code,
            'password' => 'nouveau-mdp',
            'password_confirmation' => 'nouveau-mdp',
        ];

        // Tant que l'administrateur n'a pas autorisé : refus.
        $this->postJson('/api/mot-de-passe/reinitialiser', $reinitialisation)
            ->assertUnprocessable()
            ->assertJsonPath('etat', 'EN_ATTENTE');

        // Seul l'administrateur voit et autorise la demande.
        $agent = User::where('matricule', 'AGT001')->firstOrFail();
        $this->actingAs($agent, 'sanctum')
            ->getJson('/api/admin/reinitialisations')
            ->assertForbidden();
        $admin = User::where('matricule', 'ADM001')->firstOrFail();
        $demandeId = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/reinitialisations')
            ->assertOk()
            ->assertJsonPath('demandes.0.matricule', 'AGT001')
            ->json('demandes.0.id');
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/reinitialisations/{$demandeId}/autoriser")
            ->assertOk();

        // Mauvais code refusé, bon code accepté, puis code à usage unique.
        $this->postJson(
            '/api/mot-de-passe/reinitialiser',
            ['code' => 'FAUX1234'] + $reinitialisation,
        )->assertUnprocessable();
        $this->postJson('/api/mot-de-passe/reinitialiser', $reinitialisation)->assertOk();
        $this->postJson(
            '/api/mot-de-passe/reinitialiser',
            $reinitialisation,
        )->assertUnprocessable();

        $this->assertTrue(Hash::check('nouveau-mdp', $agent->fresh()->password));
        $this->postJson('/api/login', [
            'matricule' => 'AGT001',
            'password' => 'nouveau-mdp',
        ])->assertOk();
    }
}
