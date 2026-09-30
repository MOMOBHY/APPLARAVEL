<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\DemandePermission;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Structure;
use App\Models\TypePermission;
use App\Models\User;
use App\Services\PermissionWorkflowService;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Permissions : ≤ 2 j Agent → Gestionnaire RH → Directeur/Sous-Directeur → DRH ; > 2 j Agent →
 * Gestionnaire RH → DRH. Le DRH décide seul en dernier. */
class PermissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
    }

    private function agent(string $matricule): Agent
    {
        return Agent::where('matricule', $matricule)->firstOrFail();
    }

    private function soumettre(string $matricule, string $debut, string $fin): DemandePermission
    {
        return PermissionWorkflowService::soumettre($this->agent($matricule), [
            'type_permission_id' => TypePermission::first()->id,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'motif' => 'Motif de test',
        ]);
    }

    private function chaineCas1(
        DemandePermission $demande,
        bool $valide = true,
        string $visa = 'DIRECTEUR',
    ): DemandePermission {
        $gestionnaire = $this->agent('RH001');
        $direction = $visa === 'SOUS_DIRECTEUR' ? $this->agent('SD001') : $this->agent('DIR001');
        $drh = $this->agent('DRH001');

        $demande = PermissionWorkflowService::verifierRh($demande, $gestionnaire, 'conforme');
        $demande = PermissionWorkflowService::viser($demande, $direction, $visa, true);
        $demande = PermissionWorkflowService::trancherDrh(
            $demande,
            $drh,
            $valide,
            $valide ? null : 'Motif de rejet DRH',
        );

        // Fin de chaîne : le DRH notifie l'agent directement, sans relais.
        return $demande;
    }

    /** Test 1 : permission de 1 jour, circuit complet avec visa. */
    public function test_permission_1_jour_circuit_complet(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-01');
        $this->assertEquals(1, $demande->nombre_jours);
        $this->assertEquals(DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH, $demande->statut);

        $demande = $this->chaineCas1($demande);

        $this->assertEquals(DemandePermission::VALIDEE, $demande->statut);
        $this->assertDatabaseHas('notifications', [
            'agent_id' => $demande->agent_id,
            'reference_dossier' => $demande->code_dossier,
            'type' => 'VALIDATION',
        ]);
        $actions = $demande->historique()->pluck('action')->all();
        foreach (
            [
                'SOUMISSION',
                'TRANSMISSION_VISA',
                'VISA_FAVORABLE',
                'VALIDATION_DRH',
            ] as $attendue
        ) {
            $this->assertContains($attendue, $actions);
        }
        $this->assertNotContains('NOTIFICATION_AGENT', $actions);
    }

    /** Test 2 : permission de 2 jours, même circuit avec visa. */
    public function test_permission_2_jours_circuit_complet(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-02');
        $this->assertEquals(2, $demande->nombre_jours);

        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );
        $this->assertEquals(DemandePermission::EN_ATTENTE_VISA_DIRECTEUR, $demande->statut);

        $demande = PermissionWorkflowService::viser(
            $demande,
            $this->agent('DIR001'),
            'DIRECTEUR',
            true,
        );

        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);
        $this->assertEquals(DemandePermission::VALIDEE, $demande->statut);
    }

    /** Test 3 : permission de 3 jours (> 2 j), direct DRH sans visa (Cas 2). */
    public function test_permission_3_jours_direct_drh(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-03');
        $this->assertEquals(3, $demande->nombre_jours);

        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );
        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);

        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);
        $this->assertEquals(DemandePermission::VALIDEE, $demande->statut);
    }

    /** Test 3b : permission de 4 jours, direct DRH sans visa (Cas 2). */
    public function old_test_permission_4_jours_direct_drh(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-04');
        $this->assertEquals(4, $demande->nombre_jours);

        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );
        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);

        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);
        $this->assertEquals(DemandePermission::VALIDEE, $demande->statut);
    }

    /** Test 4 : plus de 2 jours, direct DRH avec rejet motivé et notification. */
    public function test_permission_6_jours_rejet_motive_notifie(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-06');
        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );
        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);

        $demande = PermissionWorkflowService::trancherDrh(
            $demande,
            $this->agent('DRH001'),
            false,
            'Dossier incomplet',
        );
        $this->assertEquals('Dossier incomplet', $demande->motif_rejet);

        // Le rejet du DRH notifie l'agent directement, sans relais.
        $notif = Notification::where('reference_dossier', $demande->code_dossier)
            ->where('type', 'REJET')
            ->firstOrFail();
        $this->assertStringContainsString('Dossier incomplet', $notif->message);
        $this->assertStringContainsString('REJETÉE', $notif->message);
    }

    /** Test 5 : ≤ 2 jours, contournement Gestionnaire → DRH interdit. */
    public function test_cas1_pas_de_raccourci_vers_drh(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-02');
        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );
        $this->assertNotEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);

        try {
            PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);
            $this->fail('Le DRH ne doit pas trancher avant le visa.');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }
    }

    /** Test 6 : > 2 jours, aucun passage par Directeur/Sous-directeur. */
    public function test_cas2_sans_visa_direction(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-05');
        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );

        foreach (['SD001' => 'SOUS_DIRECTEUR', 'DIR001' => 'DIRECTEUR'] as $mat => $role) {
            try {
                PermissionWorkflowService::viser($demande, $this->agent($mat), $role, true);
                $this->fail("Aucun visa {$role} ne doit être possible en cas 2.");
            } catch (HttpException $e) {
                $this->assertEquals(422, $e->getStatusCode());
            }
        }
        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->refresh()->statut);
    }

    /** Test 7 : le DRH est l'autorité de validation finale (visa ne valide pas). */
    public function test_drh_seul_validateur_final(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-02');
        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );

        $demande = PermissionWorkflowService::viser(
            $demande,
            $this->agent('DIR001'),
            'DIRECTEUR',
            true,
        );

        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);
        $this->assertNotEquals(DemandePermission::VALIDEE, $demande->statut);

        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);
        $this->assertEquals(DemandePermission::VALIDEE, $demande->statut);
    }

    /** Test 8 : à la décision du DRH, l'agent est notifié directement, sans relais. */
    public function test_drh_notifie_l_agent_directement_sans_relais(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-02');
        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );

        $demande = PermissionWorkflowService::viser(
            $demande,
            $this->agent('DIR001'),
            'DIRECTEUR',
            true,
        );

        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);

        // Décision DRH : l'agent est notifié directement, aucun relais.
        $this->assertNull($demande->notifie_le);
        $this->assertDatabaseHas('notifications', [
            'agent_id' => $demande->agent_id,
            'reference_dossier' => $demande->code_dossier,
            'type' => 'VALIDATION',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'reference_dossier' => $demande->code_dossier,
            'type' => 'A_NOTIFIER',
        ]);
        $notif = Notification::where('reference_dossier', $demande->code_dossier)
            ->where('type', 'VALIDATION')
            ->firstOrFail();
        $this->assertStringContainsString('VALIDÉE', $notif->message);
        $this->assertStringContainsString('DRH', $notif->message);
    }

    /** Sous-direction → visa du Sous-directeur ; Directeur refusé à ce niveau. */
    public function test_cas1_sous_direction_visa_sous_directeur(): void
    {
        $sdPers = Structure::where('code', 'SD-PERS')->firstOrFail();
        $agent = Agent::create([
            'matricule' => 'TESTSD',
            'civilite' => 'M.',
            'nom' => 'TESTSD',
            'prenom' => 'Agent',
            'structure_id' => $sdPers->id,
        ]);

        $demande = PermissionWorkflowService::soumettre($agent, [
            'type_permission_id' => TypePermission::first()->id,
            'date_debut' => '2026-10-01',
            'date_fin' => '2026-10-02',
            'motif' => 'Test SD',
        ]);
        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );

        $this->assertEquals(DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR, $demande->statut);

        try {
            PermissionWorkflowService::viser($demande, $this->agent('DIR001'), 'DIRECTEUR', true);
            $this->fail('Le visa directeur aurait dû être refusé.');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }

        $demande = PermissionWorkflowService::viser(
            $demande,
            $this->agent('SD001'),
            'SOUS_DIRECTEUR',
            true,
        );
        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);
    }

    /** Le DRH tranche : l'agent est notifié directement, sans relais du gestionnaire. */
    public function test_drh_tranche_agent_notifie_directement(): void
    {
        $gestionnaire = User::where('matricule', 'RH001')->firstOrFail();
        $drh = User::where('matricule', 'DRH001')->firstOrFail();
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-05');

        $this->actingAs($gestionnaire, 'sanctum')
            ->postJson('/api/status', ['id' => $demande->code_dossier, 'statut' => 'EN_ATTENTE_RH'])
            ->assertOk();
        $this->actingAs($drh, 'sanctum')
            ->postJson('/api/status', ['id' => $demande->code_dossier, 'statut' => 'VALIDEE'])
            ->assertOk();

        // L'agent est notifié directement, sans étape « à notifier » pour le gestionnaire.
        $this->assertDatabaseHas('notifications', [
            'reference_dossier' => $demande->code_dossier,
            'type' => 'VALIDATION',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'reference_dossier' => $demande->code_dossier,
            'type' => 'A_NOTIFIER',
        ]);
    }

    /** L'agent choisit lui-même le nombre de jours (prime sur les dates). */
    public function test_agent_choisit_nombre_jours(): void
    {
        $demande = PermissionWorkflowService::soumettre($this->agent('AGT001'), [
            'type_permission_id' => TypePermission::first()->id,
            'date_debut' => '2026-10-01',
            'date_fin' => '2026-10-10',
            'motif' => 'Choix agent',
            'nombre_jours' => 2,
        ]);
        $this->assertEquals(2, $demande->nombre_jours);
        $this->assertEquals('2026-10-02', $demande->date_fin->format('Y-m-d'));

        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );
        $this->assertEquals(DemandePermission::EN_ATTENTE_VISA_DIRECTEUR, $demande->statut);

        try {
            PermissionWorkflowService::soumettre($this->agent('AGT001'), [
                'type_permission_id' => TypePermission::first()->id,
                'date_debut' => '2026-10-01',
                'date_fin' => '2026-10-02',
                'motif' => 'Invalide',
                'nombre_jours' => 45,
            ]);
            $this->fail('45 jours aurait dû être refusé.');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }
    }

    public function test_retour_correction_puis_resoumission(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-01');
        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'corriger',
            'Justificatif illisible',
        );

        $this->assertEquals(DemandePermission::RETOUR_CORRECTION, $demande->statut);

        $demande = PermissionWorkflowService::corrigerEtResoumettre(
            $demande,
            $this->agent('AGT001'),
            [
                'date_debut' => '2026-10-01',
                'date_fin' => '2026-10-02',
                'motif' => 'Motif corrigé',
            ],
        );
        $this->assertEquals(DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH, $demande->statut);
    }

    public function test_rejet_sans_motif_refuse(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-01');

        try {
            PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'rejeter', null);
            $this->fail('Un motif aurait dû être exigé.');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }
    }

    public function test_administrateur_sans_role_fonctionnel_hors_circuit(): void
    {
        $admin = User::where('matricule', 'ADM001')->firstOrFail();
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-02');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/permissions/{$demande->id}/verifier", ['decision' => 'conforme'])
            ->assertForbidden();

        $demande->update(['statut' => DemandePermission::EN_ATTENTE_DRH]);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/permissions/{$demande->id}/trancher", ['valide' => true])
            ->assertForbidden();
    }

    /** Cas 1 : un rejet du responsable notifie directement l'agent (motif obligatoire), sans relais. */
    public function test_refus_de_visa_notifie_directement_l_agent(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-02');
        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );
        $demande = PermissionWorkflowService::viser(
            $demande,
            $this->agent('DIR001'),
            'DIRECTEUR',
            false,
            'Service en sous-effectif',
        );

        $this->assertEquals(DemandePermission::REJETEE, $demande->statut);
        $this->assertDatabaseMissing('notifications', [
            'agent_id' => $this->agent('RH001')->id,
            'reference_dossier' => $demande->code_dossier,
            'type' => 'A_NOTIFIER',
        ]);
        $notif = Notification::where('agent_id', $demande->agent_id)
            ->where('reference_dossier', $demande->code_dossier)
            ->where('type', 'REJET')
            ->firstOrFail();
        $this->assertStringContainsString('Service en sous-effectif', $notif->message);
    }

    /** Cas 1 : le visa revient au responsable de la structure de l'agent, pas à n'importe quel directeur. */
    public function test_visa_reserve_au_responsable_de_la_structure(): void
    {
        $autreSd = Agent::create([
            'matricule' => 'SD999',
            'civilite' => 'M.',
            'nom' => 'AUTRE',
            'prenom' => 'Sousdirecteur',
        ]);
        User::create([
            'name' => 'Autre SD',
            'email' => 'sd999@x.ci',
            'matricule' => 'SD999',
            'password' => 'x',
            'agent_id' => $autreSd->id,
        ])
            ->roles()
            ->attach(Role::where('code', 'ROLE_SOUS_DIRECTEUR')->firstOrFail());
        $agent = Agent::create([
            'matricule' => 'TESTSD2',
            'civilite' => 'M.',
            'nom' => 'TESTSD',
            'prenom' => 'Deux',
            'structure_id' => Structure::where('code', 'SD-PERS')->firstOrFail()->id,
        ]);
        $demande = PermissionWorkflowService::soumettre($agent, [
            'type_permission_id' => TypePermission::first()->id,
            'date_debut' => '2026-10-01',
            'date_fin' => '2026-10-01',
            'motif' => 'Test structure',
        ]);
        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'conforme',
        );

        try {
            PermissionWorkflowService::viser($demande, $autreSd, 'SOUS_DIRECTEUR', true);
            $this->fail("Un sous-directeur d'une autre structure ne doit pas viser.");
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        $demande = PermissionWorkflowService::viser(
            $demande->refresh(),
            $this->agent('SD001'),
            'SOUS_DIRECTEUR',
            true,
        );
        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);
    }

    /** Le relais du gestionnaire est supprimé : ni la route ni le front ne peuvent plus notifier à la
     * place du DRH. */
    public function test_le_relais_de_notification_n_existe_plus(): void
    {
        $gestionnaire = User::where('matricule', 'RH001')->firstOrFail();
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-05');
        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');
        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);

        $this->actingAs($gestionnaire, 'sanctum')
            ->postJson('/api/notifier', ['id' => $demande->code_dossier])
            ->assertNotFound();
        $this->actingAs($gestionnaire, 'sanctum')
            ->postJson("/api/permissions/{$demande->id}/notifier")
            ->assertNotFound();

        $api = file_get_contents(base_path('public/gfp/js/api.js'));
        $this->assertIsString($api);
        $this->assertStringNotContainsString('notifierAgent', $api);
    }

    /** Un rejet à la vérification notifie l'agent une seule fois, directement. */
    public function test_rejet_du_gestionnaire_notifie_une_seule_fois(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-05');
        $demande = PermissionWorkflowService::verifierRh(
            $demande,
            $this->agent('RH001'),
            'rejeter',
            'Pièce manquante',
        );

        $this->assertNotNull($demande->notifie_le);
        $this->assertSame(
            1,
            Notification::where('reference_dossier', $demande->code_dossier)
                ->where('type', 'REJET')
                ->count(),
        );
    }

    /** Le gestionnaire choisit le niveau de visa, consulte le justificatif et l'agent corrige après retour. */
    public function test_gestionnaire_choisit_le_visa_consulte_la_piece_et_agent_corrige(): void
    {
        Storage::fake('local');
        $agentUser = User::where('matricule', 'AGT001')->firstOrFail();
        $gestionnaire = User::where('matricule', 'RH001')->firstOrFail();

        $this->actingAs($agentUser, 'sanctum')
            ->postJson('/api/permissions', [
                'typePerm' => 'Repos Médical',
                'motif' => 'Consultation médicale',
                'dateDebut' => '2026-10-01',
                'dateFin' => '2026-10-02',
                'piece' => UploadedFile::fake()->create('certificat.pdf', 50, 'application/pdf'),
            ])
            ->assertCreated();

        $ligne = collect(
            $this->actingAs($gestionnaire, 'sanctum')->getJson('/api/requests')->json('requests'),
        )->firstWhere('nature', 'DEMANDE_PERMISSION');
        $this->assertNotNull($ligne['piece_id']);
        $this->actingAs($gestionnaire, 'sanctum')
            ->get("/api/pieces/{$ligne['piece_id']}")
            ->assertOk();

        // Retour pour correction, puis l'agent modifie sa demande.
        $this->actingAs($gestionnaire, 'sanctum')
            ->postJson("/api/permissions/{$ligne['dossier_id']}/verifier", [
                'decision' => 'corriger',
                'motif' => 'Dates à préciser',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', DemandePermission::RETOUR_CORRECTION);
        $this->actingAs($agentUser, 'sanctum')
            ->postJson("/api/permissions/{$ligne['dossier_id']}/corriger", [
                'date_debut' => '2026-10-05',
                'date_fin' => '2026-10-05',
                'motif' => 'Consultation médicale corrigée',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH);

        // Structure « Service » : le niveau vient de la structure ; hors Sous-Direction, le dossier
        // attend la conformité du Directeur.
        $this->actingAs($gestionnaire, 'sanctum')
            ->postJson("/api/permissions/{$ligne['dossier_id']}/verifier", [
                'decision' => 'conforme',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', DemandePermission::EN_ATTENTE_VALIDATION_DIRECTEUR);
        $this->actingAs(User::where('matricule', 'DIR001')->firstOrFail(), 'sanctum')
            ->postJson('/api/status', ['id' => $ligne['id'], 'statut' => 'EN_ATTENTE_RH'])
            ->assertOk();
        $this->assertDatabaseHas('demandes_permission', [
            'id' => $ligne['dossier_id'],
            'statut' => DemandePermission::EN_ATTENTE_DRH,
        ]);
    }

    /** Suivi du dossier : le demandeur voit toutes les étapes, un autre agent n'y a pas accès. */
    public function test_suivi_du_dossier_reserve_au_demandeur(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-05');
        PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');

        $historique = $this->actingAs(User::where('matricule', 'AGT001')->firstOrFail(), 'sanctum')
            ->getJson("/api/permissions/{$demande->id}")
            ->assertOk()
            ->json('data.historique');
        $this->assertEquals(
            ['TRANSMISSION_DRH', 'SOUMISSION'],
            array_column($historique, 'action'),
        );

        $this->actingAs(User::where('matricule', 'SVC001')->firstOrFail(), 'sanctum')
            ->getJson("/api/permissions/{$demande->id}")
            ->assertForbidden();
    }
}
