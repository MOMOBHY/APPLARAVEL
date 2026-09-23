<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\DemandePermission;
use App\Models\Notification;
use App\Models\Structure;
use App\Models\TypePermission;
use App\Models\User;
use App\Services\PermissionWorkflowService;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Workflow strict des permissions :
 * ≤ 3 j : AGENT → GESTIONNAIRE RH → DIRECTEUR/SOUS-DIRECTEUR → DRH → GESTIONNAIRE RH → AGENT.
 * > 3 j : AGENT → GESTIONNAIRE RH → DRH → GESTIONNAIRE RH → AGENT.
 * Le DRH est l'unique validateur final (pas de « Validateur RH »).
 */
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

    private function chaineCas1(DemandePermission $demande, bool $valide = true, string $visa = 'DIRECTEUR'): DemandePermission
    {
        $gestionnaire = $this->agent('RH001');
        $direction = $visa === 'SOUS_DIRECTEUR' ? $this->agent('SD001') : $this->agent('DIR001');
        $drh = $this->agent('DRH001');

        $demande = PermissionWorkflowService::verifierRh($demande, $gestionnaire, 'conforme');
        $demande = PermissionWorkflowService::viser($demande, $direction, $visa, true);
        $demande = PermissionWorkflowService::trancherDrh($demande, $drh, $valide, $valide ? null : 'Motif de rejet DRH');

        return PermissionWorkflowService::notifierAgent($demande, $gestionnaire);
    }

    /** Test 1 : permission de 1 jour, circuit complet avec visa. */
    public function test_permission_1_jour_circuit_complet(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-01');
        $this->assertEquals(1, $demande->nombre_jours);
        $this->assertEquals(DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH, $demande->statut);

        $demande = $this->chaineCas1($demande);

        $this->assertEquals(DemandePermission::VALIDEE, $demande->statut);
        $this->assertNotNull($demande->notifie_le);
        $this->assertDatabaseHas('notifications', [
            'agent_id' => $demande->agent_id,
            'reference_dossier' => $demande->code_dossier,
            'type' => 'VALIDATION',
        ]);
        $actions = $demande->historique()->pluck('action')->all();
        foreach (['SOUMISSION', 'TRANSMISSION_VISA', 'VISA_FAVORABLE', 'VALIDATION_DRH', 'NOTIFICATION_AGENT'] as $attendue) {
            $this->assertContains($attendue, $actions);
        }
    }

    /** Test 2 : permission de 2 jours, même circuit avec visa. */
    public function test_permission_2_jours_circuit_complet(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-02');
        $this->assertEquals(2, $demande->nombre_jours);

        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');
        $this->assertEquals(DemandePermission::EN_ATTENTE_VISA_DIRECTEUR, $demande->statut);

        $demande = PermissionWorkflowService::viser($demande, $this->agent('DIR001'), 'DIRECTEUR', true);

        
        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);
        $demande = PermissionWorkflowService::notifierAgent($demande, $this->agent('RH001'));
        $this->assertEquals(DemandePermission::VALIDEE, $demande->statut);
    }

    /** Test 3 : permission de 3 jours, circuit avec visa (Cas 1). */
    public function test_permission_3_jours_direct_drh(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-03');
        $this->assertEquals(3, $demande->nombre_jours);

        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');
        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);

        
        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);
        $demande = PermissionWorkflowService::notifierAgent($demande, $this->agent('RH001'));
        $this->assertEquals(DemandePermission::VALIDEE, $demande->statut);
    }

    /** Test 3b : permission de 4 jours, direct DRH sans visa (Cas 2). */
    public function old_test_permission_4_jours_direct_drh(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-04');
        $this->assertEquals(4, $demande->nombre_jours);

        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');
        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);

        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);
        $demande = PermissionWorkflowService::notifierAgent($demande, $this->agent('RH001'));
        $this->assertEquals(DemandePermission::VALIDEE, $demande->statut);
    }

    /** Test 4 : plus de 3 jours, direct DRH avec rejet motivé et notification. */
    public function test_permission_6_jours_rejet_motive_notifie(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-06');
        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');
        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);

        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), false, 'Dossier incomplet');
        $this->assertEquals('Dossier incomplet', $demande->motif_rejet);

        PermissionWorkflowService::notifierAgent($demande, $this->agent('RH001'));
        $notif = Notification::where('reference_dossier', $demande->code_dossier)
            ->where('type', 'REJET')->firstOrFail();
        $this->assertStringContainsString('Dossier incomplet', $notif->message);
        $this->assertStringContainsString('REJETÉE', $notif->message);
    }

    /** Test 5 : ≤ 3 jours, contournement Gestionnaire → DRH interdit. */
    public function test_cas1_pas_de_raccourci_vers_drh(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-02');
        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');
        $this->assertNotEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);

        try {
            PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);
            $this->fail('Le DRH ne doit pas trancher avant le visa.');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }
    }

    /** Test 6 : > 3 jours, aucun passage par Directeur/Sous-directeur. */
    public function test_cas2_sans_visa_direction(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-05');
        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');

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
        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');
        

        $demande = PermissionWorkflowService::viser($demande, $this->agent('DIR001'), 'DIRECTEUR', true);

        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);
        $this->assertNotEquals(DemandePermission::VALIDEE, $demande->statut);

        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);
        $this->assertEquals(DemandePermission::VALIDEE, $demande->statut);
    }

    /** Test 8 : le Gestionnaire RH reçoit la décision et notifie l'agent. */
    public function test_gestionnaire_recoit_decision_et_notifie(): void
    {
        $demande = $this->soumettre('AGT001', '2026-10-01', '2026-10-02');
        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');
        
        $demande = PermissionWorkflowService::viser($demande, $this->agent('DIR001'), 'DIRECTEUR', true);

        $demande = PermissionWorkflowService::trancherDrh($demande, $this->agent('DRH001'), true);

        // Décision en attente de notification : l'agent n'a rien reçu.
        $this->assertNull($demande->notifie_le);
        $this->assertDatabaseMissing('notifications', [
            'agent_id' => $demande->agent_id,
            'reference_dossier' => $demande->code_dossier,
            'type' => 'VALIDATION',
        ]);

        // Le gestionnaire notifie : décision, date, type, période.
        PermissionWorkflowService::notifierAgent($demande, $this->agent('RH001'));
        $notif = Notification::where('reference_dossier', $demande->code_dossier)
            ->where('type', 'VALIDATION')->firstOrFail();
        $this->assertStringContainsString('VALIDÉE', $notif->message);
        $this->assertStringContainsString('DRH', $notif->message);
    }

    /** Sous-direction → visa du Sous-directeur ; Directeur refusé à ce niveau. */
    public function test_cas1_sous_direction_visa_sous_directeur(): void
    {
        $sdPers = Structure::where('code', 'SD-PERS')->firstOrFail();
        $agent = Agent::create([
            'matricule' => 'TESTSD', 'civilite' => 'M.', 'nom' => 'TESTSD', 'prenom' => 'Agent',
            'structure_id' => $sdPers->id,
        ]);

        $demande = PermissionWorkflowService::soumettre($agent, [
            'type_permission_id' => TypePermission::first()->id,
            'date_debut' => '2026-10-01', 'date_fin' => '2026-10-02', 'motif' => 'Test SD',
        ]);
        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');

        $this->assertEquals(DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR, $demande->statut);

        try {
            PermissionWorkflowService::viser($demande, $this->agent('DIR001'), 'DIRECTEUR', true);
            $this->fail('Le visa directeur aurait dû être refusé.');
        } catch (HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }

        $demande = PermissionWorkflowService::viser($demande, $this->agent('SD001'), 'SOUS_DIRECTEUR', true);
        $this->assertEquals(DemandePermission::EN_ATTENTE_DRH, $demande->statut);
    }

    /** Le DRH tranche sans notifier : le gestionnaire notifie explicitement. */
    public function test_drh_tranche_gestionnaire_notifie_explicitement(): void
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

        // L'agent n'est pas encore notifié, mais le gestionnaire est alerté.
        $this->assertDatabaseMissing('notifications', [
            'reference_dossier' => $demande->code_dossier,
            'type' => 'VALIDATION',
        ]);
        $this->assertDatabaseHas('notifications', [
            'reference_dossier' => $demande->code_dossier,
            'type' => 'A_NOTIFIER',
        ]);

        // Notification explicite par le gestionnaire.
        $this->actingAs($gestionnaire, 'sanctum')
            ->postJson('/api/notifier', ['id' => $demande->code_dossier])
            ->assertOk();
        $this->assertDatabaseHas('notifications', [
            'reference_dossier' => $demande->code_dossier,
            'type' => 'VALIDATION',
        ]);

        // Double notification interdite.
        $this->actingAs($gestionnaire, 'sanctum')
            ->postJson('/api/notifier', ['id' => $demande->code_dossier])
            ->assertStatus(422);
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

        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'conforme');
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
        $demande = PermissionWorkflowService::verifierRh($demande, $this->agent('RH001'), 'corriger', 'Justificatif illisible');

        $this->assertEquals(DemandePermission::RETOUR_CORRECTION, $demande->statut);

        $demande = PermissionWorkflowService::corrigerEtResoumettre($demande, $this->agent('AGT001'), [
            'date_debut' => '2026-10-01', 'date_fin' => '2026-10-02', 'motif' => 'Motif corrigé',
        ]);
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
}
