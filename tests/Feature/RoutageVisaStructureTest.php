<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Structure;
use App\Models\User;
use App\Services\PermissionWorkflowService;
use Database\Seeders\GfpSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** La conformité hiérarchique suit la structure de l'agent : Sous-Direction → sous-directeur, Direction
 * ou service rattaché → directeur. */
class RoutageVisaStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GfpSeeder::class);
        Storage::fake('local');
    }

    private function user(string $m): User
    {
        return User::where('matricule', $m)->firstOrFail();
    }

    /** Agent rattache a la structure demandee, avec le role d'agent. */
    private function agentDans(string $codeStructure, string $matricule = 'AGT-TEST'): Agent
    {
        $structure = Structure::where('code', $codeStructure)->firstOrFail();
        $user = User::firstOrCreate(
            ['matricule' => $matricule],
            [
                'name' => 'Agent '.$matricule,
                'email' => strtolower($matricule).'@fonctionpublique.gouv.ci',
                'password' => bcrypt('x'),
            ],
        );
        if (! $user->roles()->where('code', 'ROLE_AGENT')->exists()) {
            $user->roles()->attach(
                \App\Models\Role::where('code', 'ROLE_AGENT')->firstOrFail()->id,
            );
        }
        $agent = Agent::firstOrCreate(
            ['matricule' => $matricule],
            ['nom' => 'TESTEUR', 'prenom' => $matricule, 'email' => strtolower($matricule).'@fonctionpublique.gouv.ci'],
        );
        $agent->update(['structure_id' => $structure->id]);

        return $agent->fresh('structure.parent');
    }

    // Le niveau déduit de la structure

    public function test_une_sous_direction_vise_le_sous_directeur(): void
    {
        $structure = Structure::where('code', 'SDERO')->firstOrFail();
        $this->assertSame('Sous-Direction', $structure->type);
        $this->assertSame('SOUS_DIRECTEUR', PermissionWorkflowService::niveauVisaPour($structure));
    }

    public function test_une_une_sous_direction_sous_une_direction_centrale_vise_le_sous_directeur(): void
    {
        // SDERO et SDDPGED sont des Sous-Directions de la DMOA (Direction centrale).
        $dmoa = Structure::where('code', 'DMOA')->firstOrFail();
        $this->assertSame('Direction centrale', $dmoa->type);
        $this->assertSame('DIRECTEUR', PermissionWorkflowService::niveauVisaPour($dmoa));

        $this->assertSame(
            'SOUS_DIRECTEUR',
            PermissionWorkflowService::niveauVisaPour(
                Structure::where('code', 'SDDPGED')->firstOrFail(),
            ),
        );
    }

    public function test_une_direction_vise_le_directeur(): void
    {
        foreach (['DRH', 'DSI', 'DAF', 'DPSE', 'DAJC', 'DCRP', 'DQAC'] as $code) {
            $structure = Structure::where('code', $code)->firstOrFail();
            $this->assertSame('Direction', $structure->type);
            $this->assertSame('DIRECTEUR', PermissionWorkflowService::niveauVisaPour($structure), $code);
        }
    }

    public function test_une_direction_centrale_ou_generale_vise_le_directeur(): void
    {
        foreach (['DC', 'DPCE', 'DFRC', 'DGAPCE', 'DSD', 'DMOA', 'DAPSP', 'DEM', 'DGFP', 'DGTSP'] as $code) {
            $this->assertSame(
                'DIRECTEUR',
                PermissionWorkflowService::niveauVisaPour(Structure::where('code', $code)->firstOrFail()),
                $code,
            );
        }
    }

    public function test_un_service_rattache_a_une_sous_direction_remonte_au_sous_directeur(): void
    {
        $sd = Structure::where('code', 'SDERO')->firstOrFail();
        $service = Structure::create([
            'code' => 'SERV-SDERO',
            'nom' => 'Service des études de la SDERO',
            'type' => 'Service',
            'parent_id' => $sd->id,
        ]);

        // Le service n'est pas directeur : la décision vient de son parent.
        $this->assertSame('SOUS_DIRECTEUR', PermissionWorkflowService::niveauVisaPour($service->fresh('parent')));
    }

    public function test_un_service_rattache_a_une_direction_remonte_au_directeur(): void
    {
        $drh = Structure::where('code', 'DRH')->firstOrFail();
        $service = Structure::create([
            'code' => 'SERV-DRH',
            'nom' => 'Service du personnel de la DRH',
            'type' => 'Service',
            'parent_id' => $drh->id,
        ]);

        $this->assertSame('DIRECTEUR', PermissionWorkflowService::niveauVisaPour($service->fresh('parent')));
    }

    public function test_une_structure_sans_parent_vise_le_directeur(): void
    {
        // Cabinet, inspection, structures sous tutelle : aucun directeur au-dessus.
        foreach (['CAB', 'IG', 'ENA', 'SOMFP'] as $code) {
            $this->assertSame(
                'DIRECTEUR',
                PermissionWorkflowService::niveauVisaPour(Structure::where('code', $code)->firstOrFail()),
                $code,
            );
        }
    }

    public function test_une_structure_absente_vise_le_directeur_sans_erreur(): void
    {
        $this->assertSame('DIRECTEUR', PermissionWorkflowService::niveauVisaPour(null));
    }

    public function test_la_remontee_resiste_a_un_cycle_de_parent(): void
    {
        $a = Structure::create(['code' => 'CYC-A', 'nom' => 'Cycle A', 'type' => 'Service']);
        $b = Structure::create(['code' => 'CYC-B', 'nom' => 'Cycle B', 'type' => 'Service']);
        $a->update(['parent_id' => $b->id]);
        $b->update(['parent_id' => $a->id]);

        // Doit se terminer malgré la boucle, et rester un cas sûr.
        $this->assertSame('DIRECTEUR', PermissionWorkflowService::niveauVisaPour($a->fresh('parent')));
    }

    // Le circuit de vérification respecte ce niveau

    /** Soumet une permission au nom de l'agent donné et renvoie la demande créée. On passe par le service
     * plutôt que par l'API : seul le niveau de routage est à vérifier ici, pas le contrat HTTP. */
    private function soumettre(Agent $agent, int $jours): \App\Models\DemandePermission
    {
        $type = \App\Models\TypePermission::firstOrCreate(
            ['libelle' => 'Événement Familial'],
            ['duree_max' => 30],
        );

        return PermissionWorkflowService::soumettre($agent, [
            'type_permission_id' => $type->id,
            'motif' => 'Démarche personnelle',
            'date_debut' => '2026-10-01',
            'nombre_jours' => $jours,
        ]);
    }

    public function test_le_dossier_d_un_agent_de_sous_direction_attend_le_sous_directeur(): void
    {
        $agent = $this->agentDans('SDERO', 'AGT-SDERO');
        $demande = $this->soumettre($agent, 2);

        PermissionWorkflowService::verifierRh($demande, $this->user('RH001')->agent, 'conforme');

        $this->assertSame('SOUS_DIRECTEUR', $demande->fresh()->visa_attendu);
        $this->assertSame(
            \App\Models\DemandePermission::EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR,
            $demande->fresh()->statut,
        );
    }

    public function test_le_dossier_d_un_agent_de_direction_attend_le_directeur(): void
    {
        $agent = $this->agentDans('DAF', 'AGT-DAF');
        $demande = $this->soumettre($agent, 2);

        PermissionWorkflowService::verifierRh($demande, $this->user('RH001')->agent, 'conforme');

        $this->assertSame('DIRECTEUR', $demande->fresh()->visa_attendu);
        $this->assertSame(
            \App\Models\DemandePermission::EN_ATTENTE_VALIDATION_DIRECTEUR,
            $demande->fresh()->statut,
        );
    }

    public function test_un_agent_d_un_service_va_au_directeur_de_ce_service(): void
    {
        $agent = $this->agentDans('SERV-ETUDES', 'AGT-SERV');
        $demande = $this->soumettre($agent, 2);

        PermissionWorkflowService::verifierRh($demande, $this->user('RH001')->agent, 'conforme');

        $this->assertSame('DIRECTEUR', $demande->fresh()->visa_attendu);
    }

    public function test_le_gestionnaire_ne_peut_pas_inverser_le_niveau_de_la_structure(): void
    {
        $agent = $this->agentDans('SDERO', 'AGT-SDERO2');
        $demande = $this->soumettre($agent, 2);

        // Le gestionnaire tente d'imposer un Directeur : la structure tranche.
        PermissionWorkflowService::verifierRh(
            $demande,
            $this->user('RH001')->agent,
            'conforme',
            null,
            'DIRECTEUR',
        );

        $this->assertSame(
            'SOUS_DIRECTEUR',
            $demande->fresh()->visa_attendu,
            'Le niveau doit suivre la structure, pas une demande de transmission.',
        );
    }

    public function test_une_demande_longue_va_toujours_directement_a_la_drh(): void
    {
        $agent = $this->agentDans('SDERO', 'AGT-SDERO3');
        $demande = $this->soumettre($agent, 20);

        PermissionWorkflowService::verifierRh($demande, $this->user('RH001')->agent, 'conforme');

        // Le changement ne concerne que le cas court.
        $this->assertSame(
            \App\Models\DemandePermission::EN_ATTENTE_DRH,
            $demande->fresh()->statut,
        );
        $this->assertNull($demande->fresh()->visa_attendu);
    }
}
