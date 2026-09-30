<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Barre d'actions des dépôts : vert pour Modifier, Supprimer et Soumettre ; « Enregistrer dans le
 * brouillon » en gris bien visible. Testé sur le HTML servi. */
class BoutonsFormulairesTest extends TestCase
{
    private const VERT = 'bg-emerald-700 hover:bg-emerald-800 text-white';

    /** Le gris du brouillon doit être un aplat, pas une teinte : visible d'un coup d'œil. */
    private const GRIS = 'bg-slate-600 hover:bg-slate-700 text-white';

    /** Vues qui portent des formulaires de dépôt. */
    private const VUES = ['views/agent.html', 'views/responsable.html'];

    /** Formulaires de dépôt ouverts par « Nouvelle… », tous profils confondus. */
    private function formulaires(): array
    {
        $trouves = [];
        foreach (self::VUES as $vue) {
            $html = file_get_contents(base_path('public/gfp/'.$vue));
            $this->assertIsString($html, "La vue {$vue} doit être servie.");

            preg_match_all('/<form id="(form\w+)".*?<\/form>/s', $html, $occurrences);
            foreach ($occurrences[1] as $index => $id) {
                $corps = $occurrences[0][$index];
                /* Le formulaire de note de service n'a pas de brouillon : il ne porte pas la barre
                   d'actions testée ici. */
                if (! str_contains($corps, '-btn-enregistrer')) {
                    continue;
                }
                $trouves[$vue.'#'.$id] = $corps;
            }
        }

        $this->assertNotEmpty($trouves, 'Des formulaires de dépôt doivent être présents.');
        $this->assertCount(5, $trouves, 'Trois formulaires côté agent, deux côté responsable.');

        return $trouves;
    }

    /** Classe de tous les boutons d'un formulaire. */
    private function boutons(string $formulaire): array
    {
        preg_match_all('/<button\b[^>]*?>/s', $formulaire, $trouves);

        return array_map(
            static function (string $bouton): string {
                preg_match('/class="([^"]*)"/s', $bouton, $classe);

                return $classe[1] ?? '';
            },
            $trouves[0],
        );
    }

    public function test_chaque_formulaire_propose_quatre_boutons_d_action(): void
    {
        foreach ($this->formulaires() as $libelle => $formulaire) {
            $this->assertCount(4, $this->boutons($formulaire), "Formulaire {$libelle}.");
        }
    }

    /** Trois boutons verts (Modifier, Supprimer, Soumettre) et le brouillon en gris. */
    public function test_seul_le_bouton_de_brouillon_est_gris_les_autres_sont_verts(): void
    {
        foreach ($this->formulaires() as $libelle => $formulaire) {
            $classes = $this->boutons($formulaire);
            $gris = [];
            foreach ($classes as $classe) {
                if (str_contains($classe, 'bg-slate-')) {
                    $gris[] = $classe;
                } else {
                    $this->assertStringContainsString(
                        self::VERT,
                        $classe,
                        "Dans {$libelle}, un bouton d'action n'est pas vert : « {$classe} ».",
                    );
                }
            }

            $this->assertCount(1, $gris, "Dans {$libelle}, seul « Enregistrer dans le brouillon » est gris.");
            $this->assertStringContainsString(
                self::GRIS,
                $gris[0],
                "Dans {$libelle}, le bouton de brouillon n'est pas le gris attendu : « {$gris[0]} ».",
            );
        }
    }

    /** Le gris du brouillon doit sauter aux yeux : un aplat avec du texte blanc. Une teinte claire
     * (bg-slate-100/200) passerait inaperçue au bas du formulaire. */
    public function test_le_bouton_de_brouillon_est_clairement_visible(): void
    {
        foreach ($this->formulaires() as $libelle => $formulaire) {
            $gris = array_values(array_filter($this->boutons($formulaire), fn ($c) => str_contains($c, 'bg-slate-')));
            $classe = $gris[0];

            $this->assertStringContainsString(
                'text-white',
                $classe,
                "Dans {$libelle}, le texte du bouton de brouillon doit être blanc pour ressortir.",
            );
            $this->assertStringNotContainsString(
                'text-slate-',
                $classe,
                "Dans {$libelle}, un texte gris sur fond gris rendrait le bouton illisible.",
            );
        }
    }

    /** Aucune couleur concurrente ne doit subsister dans les barres d'actions. */
    public function test_aucune_autre_couleur_ne_subsiste_dans_les_formulaires(): void
    {
        $interdits = [
            'bg-red-', 'bg-amber-', 'bg-orange-', 'bg-sky-', 'bg-blue-',
            'bg-yellow-', 'bg-slate-100', 'bg-slate-200', 'bg-slate-300',
            'bg-slate-500', 'text-red-',
        ];

        foreach ($this->formulaires() as $libelle => $formulaire) {
            foreach ($this->boutons($formulaire) as $classe) {
                foreach ($interdits as $couleur) {
                    $this->assertStringNotContainsString(
                        $couleur,
                        $classe,
                        "La couleur « {$couleur} » a réapparu dans {$libelle} : « {$classe} ».",
                    );
                }
            }
        }
    }

    /** Modifier et Supprimer ne s'affichent qu'avec un brouillon : le « hidden » doit rester. */
    public function test_les_boutons_conditionnes_restent_masques_sans_brouillon(): void
    {
        foreach ($this->formulaires() as $libelle => $formulaire) {
            preg_match_all('/<button\b[^>]*?>/s', $formulaire, $trouves);
            $etats = [];
            foreach ($trouves[0] as $bouton) {
                preg_match('/id="(\w+?-btn-\w+)"/s', $bouton, $id);
                $etats[$id[1] ?? 'soumettre'] = str_contains($bouton, 'hidden');
            }

            // 2 visibles d'office (Enregistrer, Soumettre), 2 conditionnels (Modifier, Supprimer).
            $visibles = array_keys(array_filter($etats, fn (bool $c) => ! $c));
            $caches = array_keys(array_filter($etats, fn (bool $c) => $c));
            sort($visibles);
            sort($caches);

            $this->assertCount(2, $visibles, "Deux boutons sont visibles d'office dans {$libelle}.");
            $this->assertCount(2, $caches, "Deux boutons n'apparaissent qu'avec un brouillon dans {$libelle}.");
            $this->assertStringEndsWith('-btn-enregistrer', $visibles[0]);
            $this->assertStringEndsWith('-btn-modifier', $caches[0]);
            $this->assertStringEndsWith('-btn-supprimer', $caches[1]);
        }
    }
}
