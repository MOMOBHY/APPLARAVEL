<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Notification;
use App\Models\Structure;
use App\Models\User;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Aucun dépôt tant que l'agent n'est pas rattaché à une Direction ou Sous-Direction ; ce contrôle
 * serveur fait autorité sur l'interface. */
class GardeStructureAgentTest extends TestCase
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

    /** Rattache l'agent au code de structure donné (ou le détache si null). */
    private function rattacher(string $matricule, ?string $codeStructure): void
    {
        $id = $codeStructure
            ? Structure::where('code', $codeStructure)->firstOrFail()->id
            : null;
        Agent::where('matricule', $matricule)->update(['structure_id' => $id]);
    }

    private function piece(): UploadedFile
    {
        return UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf');
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

    private function payloadNaissance(): array
    {
        return [
            'nature' => 'NAISSANCE',
            'nom' => 'KONE',
            'prenom' => 'Awa',
            'date' => '2026-09-20',
            'lieu' => 'Abidjan',
            'extrait' => $this->piece(),
        ];
    }

    // Le dépôt est refusé tant que la structure n'est pas affectée

    public function test_sans_structure_la_permission_est_refusee(): void
    {
        $this->rattacher('AGT001', null);

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions', $this->payloadPermission() + ['piece' => $this->piece()])
            ->assertForbidden();
    }

    public function test_sans_structure_la_declaration_est_refusee(): void
    {
        $this->rattacher('AGT001', null);

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/declarations', $this->payloadNaissance() + ['piece' => $this->piece()])
            ->assertForbidden();
    }

    public function test_sans_structure_le_brouillon_de_permission_est_refuse(): void
    {
        $this->rattacher('AGT001', null);

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions/brouillon', $this->payloadPermission())
            ->assertForbidden();
    }

    public function test_sans_structure_le_brouillon_de_declaration_est_refuse(): void
    {
        $this->rattacher('AGT001', null);

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/declarations/brouillon', $this->payloadNaissance())
            ->assertForbidden();
    }

    /** Un service de l'annuaire est une structure affectée : le dépôt s'ouvre. */
    public function test_un_rattachement_a_un_service_ouvre_le_depot(): void
    {
        $this->rattacher('AGT001', 'SERV-ETUDES');

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions', $this->payloadPermission() + ['piece' => $this->piece()])
            ->assertCreated();
    }

    // Le dépôt redevient possible une fois la structure affectée

    public function test_une_direction_ou_une_sous_direction_debloque_la_permission(): void
    {
        foreach (['DAF' => 'Direction', 'SD-PERS' => 'Sous-Direction'] as $code => $type) {
            $this->rattacher('AGT001', $code);
            $this->assertSame($type, Structure::where('code', $code)->firstOrFail()->type);

            $this->actingAs($this->user('AGT001'), 'sanctum')
                ->postJson('/api/permissions', $this->payloadPermission() + ['piece' => $this->piece()])
                ->assertCreated();
        }
    }

    public function test_une_direction_ou_une_sous_direction_debloque_la_declaration(): void
    {
        foreach (['DAF', 'SD-PERS'] as $code) {
            $this->rattacher('AGT001', $code);

            $this->actingAs($this->user('AGT001'), 'sanctum')
                ->postJson('/api/declarations', $this->payloadNaissance() + ['piece' => $this->piece()])
                ->assertCreated();
        }
    }

    /** Le garde-fou ne concerne que les agents : le gestionnaire RH garde ses moyens. */
    public function test_le_garde_fou_concerne_seulement_les_agents(): void
    {
        $this->rattacher('RH001', null);

        $this->actingAs($this->user('RH001'), 'sanctum')
            ->getJson('/api/requests')
            ->assertOk();
    }

    // L'affectation déclenche une notification

    public function test_l_affectation_notifie_l_agent(): void
    {
        $this->rattacher('AGT001', null);
        $admin = $this->user('ADM001');
        $agentId = Agent::where('matricule', 'AGT001')->firstOrFail()->id;

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users/update', [
                'matricule' => 'AGT001',
                'structure_id' => Structure::where('code', 'DAF')->firstOrFail()->id,
            ])
            ->assertOk();

        $notif = Notification::where('agent_id', $agentId)->first();
        $this->assertNotNull($notif, 'Une notification doit être envoyée à l\'agent affecté.');
        $this->assertSame('STRUCTURE', $notif->type);
        $this->assertStringContainsString('Direction des Affaires Financières', $notif->message);
        $this->assertStringContainsString('Vous pouvez désormais déposer', $notif->message);
        $this->assertStringContainsString('relèvent du Directeur', $notif->message);
    }

    /** Un rattachement dans une structure de l'annuaire ouvre le dépôt, quel que soit son type. */
    public function test_un_rattachement_dans_une_structure_de_l_annuaire_ouvre_le_depot(): void
    {
        $this->rattacher('AGT001', null);
        $agentId = Agent::where('matricule', 'AGT001')->firstOrFail()->id;

        $this->actingAs($this->user('ADM001'), 'sanctum')
            ->postJson('/api/users/update', [
                'matricule' => 'AGT001',
                'structure_id' => Structure::where('code', 'CAB')->firstOrFail()->id,
            ])
            ->assertOk();

        $message = Notification::where('agent_id', $agentId)->firstOrFail()->message;
        $this->assertStringContainsString('Vous pouvez désormais déposer', $message);

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/permissions', $this->payloadPermission() + ['piece' => $this->piece()])
            ->assertCreated();
    }

    public function test_le_message_adapte_le_niveau_de_visa_a_la_sous_direction(): void
    {
        $admin = $this->user('ADM001');
        $agentId = Agent::where('matricule', 'AGT001')->firstOrFail()->id;

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users/update', [
                'matricule' => 'AGT001',
                'structure_id' => Structure::where('code', 'SD-PERS')->firstOrFail()->id,
            ])
            ->assertOk();

        $message = Notification::where('agent_id', $agentId)->firstOrFail()->message;
        $this->assertStringContainsString('relèvent du Sous-Directeur', $message);
        $this->assertStringNotContainsString('relèvent du Directeur', $message);
    }

    public function test_un_enregistrement_sans_changement_ne_notifie_pas(): void
    {
        $this->rattacher('AGT001', 'DAF');
        $agentId = Agent::where('matricule', 'AGT001')->firstOrFail()->id;
        Notification::where('agent_id', $agentId)->delete();

        $this->actingAs($this->user('ADM001'), 'sanctum')
            ->postJson('/api/users/update', [
                'matricule' => 'AGT001',
                'structure_id' => Structure::where('code', 'DAF')->firstOrFail()->id,
            ])
            ->assertOk();

        $this->assertSame(0, Notification::where('agent_id', $agentId)->count());
    }

    public function test_l_affectation_est_tracee_dans_le_journal(): void
    {
        $this->rattacher('AGT001', null);

        $this->actingAs($this->user('ADM001'), 'sanctum')
            ->postJson('/api/users/update', [
                'matricule' => 'AGT001',
                'structure_id' => Structure::where('code', 'DAF')->firstOrFail()->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('journal_audit', [
            'categorie' => 'COMPTE',
            'action' => 'COMPTE_MODIFIE',
            'reference' => 'AGT001',
        ]);
    }

    // L'interface connaît l'état d'affectation

    public function test_le_compte_rappelle_son_nom_de_structure_et_son_type(): void
    {
        $this->rattacher('AGT001', 'SD-PERS');
        $structureId = Structure::where('code', 'SD-PERS')->firstOrFail()->id;

        $this->actingAs($this->user('AGT001'), 'sanctum')
            ->postJson('/api/login', ['matricule' => 'AGT001', 'password' => 'test123'])
            ->assertOk()
            ->assertJsonPath('user.structure', 'Sous-Direction du Personnel')
            ->assertJsonPath('user.structure_type', 'Sous-Direction')
            ->assertJsonPath('user.structure_id', $structureId);
    }

    /** Sans structure, la session l'annonce : l'interface verrouille ses onglets. */
    public function test_le_compte_sans_structure_annonce_l_absence_de_rattachement(): void
    {
        $this->rattacher('AGT001', null);

        $this->postJson('/api/login', ['matricule' => 'AGT001', 'password' => 'test123'])
            ->assertOk()
            ->assertJsonPath('user.structure', null)
            ->assertJsonPath('user.structure_id', null);
    }
}
