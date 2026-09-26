<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Fonction;
use App\Models\Privilege;
use App\Models\Role;
use App\Models\Structure;
use App\Models\TypePermission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class GfpSeeder extends Seeder
{
    public function run(): void
    {
        $structures = [
            ['code' => 'CAB', 'nom' => 'Cabinet du Ministre', 'type' => 'Cabinet'],
            ['code' => 'DRH', 'nom' => 'Direction des Ressources Humaines', 'type' => 'Direction'],
            [
                'code' => 'DSI',
                'nom' => 'Direction des Systèmes d’Information',
                'type' => 'Direction',
            ],
            [
                'code' => 'SD-PERS',
                'nom' => 'Sous-Direction du Personnel',
                'type' => 'Sous-Direction',
            ],
            ['code' => 'SERV-ETUDES', 'nom' => 'Service des Études', 'type' => 'Service'],
        ];
        foreach ($structures as $s) {
            Structure::firstOrCreate(['code' => $s['code']], $s);
        }
        $this->call(StructuresMinistereSeeder::class);

        $fonctions = [
            ['code' => 'AGENT', 'libelle' => 'Agent', 'niveau_hierarchique' => 1],
            ['code' => 'GEST_RH', 'libelle' => 'Gestionnaire RH', 'niveau_hierarchique' => 2],
            ['code' => 'SD', 'libelle' => 'Sous-Directeur', 'niveau_hierarchique' => 3],
            ['code' => 'DIR', 'libelle' => 'Directeur', 'niveau_hierarchique' => 4],
            ['code' => 'DRH', 'libelle' => 'Directeur RH', 'niveau_hierarchique' => 5],
        ];
        foreach ($fonctions as $f) {
            Fonction::firstOrCreate(['code' => $f['code']], $f);
        }

        $roles = [
            'ROLE_AGENT' => 'Agent',
            'ROLE_GESTIONNAIRE_RH' => 'Gestionnaire RH',
            'ROLE_SOUS_DIRECTEUR' => 'Sous-Directeur',
            'ROLE_DIRECTEUR' => 'Directeur',
            'ROLE_DRH' => 'Directeur des Ressources Humaines',
            'ROLE_SECRETAIRE' => 'Secrétaire',
            'ROLE_SERVICE_ADMINISTRATIF' => 'Service chargé de la gestion administrative',
            'ROLE_CHEF_DE_SERVICE' => 'Chef de Service',
            'ROLE_DIRECTEUR_CABINET' => 'Directeur de Cabinet',
            'ROLE_ADMIN_DSI' => 'Administrateur DSI',
        ];
        foreach ($roles as $code => $lib) {
            Role::firstOrCreate(['code' => $code], ['libelle' => $lib]);
        }

        $privileges = [
            ['code' => 'PERM_CREER', 'libelle' => 'Créer permission', 'module' => 'PERMISSIONS'],
            [
                'code' => 'PERM_VERIFIER',
                'libelle' => 'Vérifier conformité RH',
                'module' => 'PERMISSIONS',
            ],
            ['code' => 'PERM_VISER', 'libelle' => 'Viser direction', 'module' => 'PERMISSIONS'],
            ['code' => 'PERM_VALID_DRH', 'libelle' => 'Valider DRH', 'module' => 'PERMISSIONS'],
            ['code' => 'ETAT_VAL', 'libelle' => 'Valider état civil', 'module' => 'ETAT_CIVIL'],
            ['code' => 'NOTE_SIGNER', 'libelle' => 'Signer note', 'module' => 'NOTES'],
            ['code' => 'NOTE_SAISIR', 'libelle' => 'Saisir note', 'module' => 'NOTES'],
        ];
        foreach ($privileges as $p) {
            Privilege::firstOrCreate(['code' => $p['code']], $p);
        }

        foreach (
            [
                'Événement Familial',
                'Repos Médical',
                'Permission Spéciale',
                'Congé Maternité / Paternité',
            ] as $lib
        ) {
            TypePermission::firstOrCreate(['libelle' => $lib], ['duree_max' => 30]);
        }

        $comptes = [
            [
                'matricule' => 'AGT001',
                'nom' => 'GRAMBOUTE',
                'prenom' => 'Mohamed',
                'role' => 'ROLE_AGENT',
                'structure' => 'SERV-ETUDES',
            ],
            [
                'matricule' => 'RH001',
                'nom' => 'KOUAME',
                'prenom' => 'Awa',
                'role' => 'ROLE_GESTIONNAIRE_RH',
                'structure' => 'DRH',
            ],
            [
                'matricule' => 'SD001',
                'nom' => 'BROU',
                'prenom' => 'Marc',
                'role' => 'ROLE_SOUS_DIRECTEUR',
                'structure' => 'SD-PERS',
            ],
            [
                'matricule' => 'DIR001',
                'nom' => 'KONE',
                'prenom' => 'Ibrahim',
                'role' => 'ROLE_DIRECTEUR',
                'structure' => 'DSI',
            ],
            [
                'matricule' => 'DRH001',
                'nom' => 'ADJOUA',
                'prenom' => 'Marie',
                'role' => 'ROLE_DRH',
                'structure' => 'DRH',
            ],
            [
                'matricule' => 'SEC001',
                'nom' => 'YAPO',
                'prenom' => 'Chantal',
                'role' => 'ROLE_SECRETAIRE',
                'structure' => 'CAB',
            ],
            [
                'matricule' => 'SVC001',
                'nom' => 'DIALLO',
                'prenom' => 'Aminata',
                'role' => 'ROLE_SERVICE_ADMINISTRATIF',
                'structure' => 'DRH',
            ],
            [
                'matricule' => 'CHEF001',
                'nom' => 'TRAORE',
                'prenom' => 'Siaka',
                'role' => 'ROLE_CHEF_DE_SERVICE',
                'structure' => 'SERV-ETUDES',
            ],
            [
                'matricule' => 'CAB001',
                'nom' => 'N_GUESSAN',
                'prenom' => 'Koffi',
                'role' => 'ROLE_DIRECTEUR_CABINET',
                'structure' => 'CAB',
            ],
            [
                'matricule' => 'ADM001',
                'nom' => 'SYSADMIN',
                'prenom' => 'Root',
                'role' => 'ROLE_ADMIN_DSI',
                'structure' => 'DSI',
            ],
            // Comptes historiques du frontend d'origine (mêmes matricules).
            [
                'matricule' => '000001X',
                'nom' => 'GRAMBOUTE',
                'prenom' => 'Mohamed Prince',
                'role' => 'ROLE_AGENT',
                'structure' => 'SERV-ETUDES',
            ],
            [
                'matricule' => '000002A',
                'nom' => 'KOUASSI',
                'prenom' => 'Jean-Marc',
                'role' => 'ROLE_GESTIONNAIRE_RH',
                'structure' => 'SD-PERS',
            ],
            [
                'matricule' => '000003B',
                'nom' => 'ADJOUA',
                'prenom' => 'Marie-Claire',
                'role' => 'ROLE_DRH',
                'structure' => 'DRH',
            ],
            [
                'matricule' => '000004Z',
                'nom' => 'KONE',
                'prenom' => 'Ousmane',
                'role' => 'ROLE_ADMIN_DSI',
                'structure' => 'DSI',
            ],
        ];

        foreach ($comptes as $c) {
            $agent = Agent::firstOrCreate(
                ['matricule' => $c['matricule']],
                [
                    'civilite' => 'M.',
                    'nom' => $c['nom'],
                    'prenom' => $c['prenom'],
                    'structure_id' => Structure::where('code', $c['structure'])->first()->id,
                ],
            );
            $user = User::firstOrCreate(
                ['matricule' => $c['matricule']],
                [
                    'name' => $agent->fullName(),
                    'email' => strtolower($c['matricule']).'@fonctionpublique.gouv.ci',
                    'password' => Hash::make('test123'),
                    'agent_id' => $agent->id,
                    'structure_id' => $agent->structure_id,
                ],
            );
            $user->roles()->syncWithoutDetaching([Role::where('code', $c['role'])->first()->id]);
            foreach ($c['extra'] ?? [] as $extra) {
                $user->roles()->syncWithoutDetaching([Role::where('code', $extra)->first()->id]);
            }
        }

        // Responsables hiérarchiques par structure (résolution auto du visa).
        $responsables = [
            'SD-PERS' => 'SD001',
            'DSI' => 'DIR001',
            'DRH' => 'DRH001',
            'SERV-ETUDES' => 'SD001',
        ];
        foreach ($responsables as $code => $matricule) {
            $structure = Structure::where('code', $code)->first();
            $agent = Agent::where('matricule', $matricule)->first();
            if ($structure && $agent) {
                $structure->update(['responsable_agent_id' => $agent->id]);
            }
        }
    }
}
