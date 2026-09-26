<?php

namespace Database\Seeders;

use App\Models\Structure;
use Illuminate\Database\Seeder;

/**
 * Structures du Ministère de la Fonction Publique et de la Modernisation de l'Administration (Côte d'Ivoire).
 * Source : rubrique « Organisation du ministère » de fonctionpublique.gouv.ci (décret n° 2022-598 du 3 août 2022),
 * complétée pour la DMOA par l'INSP. La liste des sous-directions est partielle : seules celles publiées sont reprises.
 */
class StructuresMinistereSeeder extends Seeder
{
    /** [code, nom, sigle, type, code du parent] */
    public const STRUCTURES = [
        // Cabinet et services rattachés
        ['CAB', 'Cabinet du Ministre', null, 'Cabinet', null],
        ['IG', 'Inspection Générale', 'IG', 'Service rattaché', null],
        ['CD', 'Conseil de Discipline', null, 'Organe', null],
        [
            'SOMFP',
            'Secrétariat de l’Ordre du Mérite de la Fonction Publique',
            'SOMFP',
            'Service rattaché',
            null,
        ],
        [
            'DQAC',
            'Direction de la Qualité et de l’Accompagnement du Changement',
            'DQAC',
            'Direction',
            null,
        ],
        ['DSI', 'Direction des Systèmes d’Information', 'DSI', 'Direction', null],
        ['DRH', 'Direction des Ressources Humaines', 'DRH', 'Direction', null],
        ['DAF', 'Direction des Affaires Financières', 'DAF', 'Direction', null],
        [
            'DPSE',
            'Direction de la Planification, des Statistiques et de l’Évaluation',
            'DPSE',
            'Direction',
            null,
        ],
        ['DAJC', 'Direction des Affaires Juridiques et du Contentieux', 'DAJC', 'Direction', null],
        [
            'DCRP',
            'Direction de la Communication et des Relations Publiques',
            'DCRP',
            'Direction',
            null,
        ],

        // Direction Générale de la Fonction Publique
        ['DGFP', 'Direction Générale de la Fonction Publique', 'DGFP', 'Direction générale', null],
        ['DC', 'Direction des Concours', 'DC', 'Direction centrale', 'DGFP'],
        [
            'DPCE',
            'Direction de la Programmation et du Contrôle des Effectifs',
            'DPCE',
            'Direction centrale',
            'DGFP',
        ],
        [
            'DFRC',
            'Direction de la Formation et du Renforcement des Capacités',
            'DFRC',
            'Direction centrale',
            'DGFP',
        ],
        [
            'DGAPCE',
            'Direction de la Gestion Administrative des Personnels Civils de l’État',
            'DGAPCE',
            'Direction centrale',
            'DGFP',
        ],
        [
            'DSD',
            'Direction des Services Déconcentrés (Directions Régionales et Antennes)',
            'DSD',
            'Direction centrale',
            'DGFP',
        ],

        // Direction Générale de la Transformation du Service Public
        [
            'DGTSP',
            'Direction Générale de la Transformation du Service Public',
            'DGTSP',
            'Direction générale',
            null,
        ],
        [
            'DMOA',
            'Direction de la Modernisation de l’Organisation Administrative',
            'DMOA',
            'Direction centrale',
            'DGTSP',
        ],
        [
            'DAPSP',
            'Direction de l’Appui à la Performance du Service Public',
            'DAPSP',
            'Direction centrale',
            'DGTSP',
        ],
        ['DEM', 'Direction des Études et Méthodes', 'DEM', 'Direction centrale', 'DGTSP'],
        [
            'SDERO',
            'Sous-Direction des Études et de la Restructuration des Organisations',
            'SDERO',
            'Sous-Direction',
            'DMOA',
        ],
        [
            'SDDPGED',
            'Sous-Direction de la Dématérialisation des Procédures et de la Gestion Électronique des Documents',
            'SDDPGED',
            'Sous-Direction',
            'DMOA',
        ],

        // Structures sous tutelle
        ['ENA', 'École Nationale d’Administration', 'ENA', 'Structure sous tutelle', null],
        [
            'CPFAE',
            'Centre de Perfectionnement des Fonctionnaires et Agents de l’État',
            'CPFAE',
            'Structure sous tutelle',
            null,
        ],
        ['CED-CI', 'CED-CI', 'CED-CI', 'Structure sous tutelle', null],
    ];

    public function run(): void
    {
        foreach (self::STRUCTURES as [$code, $nom, $sigle, $type]) {
            Structure::updateOrCreate(
                ['code' => $code],
                ['nom' => $nom, 'sigle' => $sigle, 'type' => $type, 'officielle' => true],
            );
        }
        foreach (self::STRUCTURES as [$code, , , , $parent]) {
            if ($parent) {
                Structure::where('code', $code)->update([
                    'parent_id' => Structure::where('code', $parent)->value('id'),
                ]);
            }
        }

        // Structures fictives des comptes de démonstration : conservées, mais hors annuaire officiel.
        Structure::whereIn('code', ['SD-PERS', 'SERV-ETUDES'])->update(['officielle' => false]);
    }
}
