<?php

namespace App\Http\Controllers;

use App\Models\DeclarationDeces;
use App\Models\DeclarationNaissance;
use App\Models\DemandePermission;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Tableau de bord du DRH et de l'administrateur : volumes par mois, par statut,
 * par structure, et délai moyen entre le dépôt et la décision finale.
 */
class StatistiquesController extends Controller
{
    private const NATURES = [
        'permission' => 'Permissions',
        'naissance' => 'Naissances',
        'deces' => 'Décès',
    ];

    /**
     * Tableau de bord statistique (DRH et administrateur) : indicateurs, dossiers par mois, par
     * statut, par structure et délai moyen de traitement.
     */
    public function index()
    {
        $dossiers = $this->dossiers();

        $parStatut = collect(['validee', 'a_corriger', 'en_attente', 'rejetee'])->mapWithKeys(
            fn (string $statut) => [$statut => $dossiers->where('statut', $statut)->count()],
        );
        $tranches = $parStatut['validee'] + $parStatut['rejetee'];

        return response()->json([
            'status' => 'success',
            'indicateurs' => [
                'total' => $dossiers->count(),
                'en_cours' => $parStatut['en_attente'] + $parStatut['a_corriger'],
                'taux_validation' => $tranches
                    ? round(($parStatut['validee'] * 100) / $tranches)
                    : null,
                'delai_moyen_jours' => $this->delaiMoyen($dossiers),
            ],
            'par_mois' => $this->parMois($dossiers),
            'par_statut' => $parStatut,
            'par_structure' => $dossiers
                ->groupBy('structure')
                ->map(
                    fn (Collection $groupe, string $structure) => [
                        'structure' => $structure,
                        'total' => $groupe->count(),
                    ],
                )
                ->sortByDesc('total')
                ->values(),
            'delai_par_nature' => collect(self::NATURES)
                ->map(
                    fn (string $libelle, string $nature) => [
                        'nature' => $libelle,
                        'delai_moyen_jours' => $this->delaiMoyen(
                            $dossiers->where('nature', $nature),
                        ),
                        'dossiers_clos' => $dossiers
                            ->where('nature', $nature)
                            ->whereNotNull('cloture')
                            ->count(),
                    ],
                )
                ->values(),
        ]);
    }

    /**
     * Toutes les demandes ramenées à un format commun.
     *
     * @return Collection<int, array{nature: string, depot: CarbonInterface, statut: string, cloture: ?CarbonInterface, structure: string}>
     */
    private function dossiers(): Collection
    {
        $permissions = DemandePermission::with('agent.structure')->get()->map(
            fn (DemandePermission $d) => [
                'nature' => 'permission',
                'depot' => $d->created_at,
                'statut' => $this->categorie($d->statut, $d->motif_rejet),
                // Décision DRH, ou refus de visa, ou rejet à la vérification.
                'cloture' => in_array(
                    $d->statut,
                    [DemandePermission::VALIDEE, DemandePermission::REJETEE],
                    true,
                )
                    ? $d->date_decision ?? ($d->date_visa ?? $d->date_verif_rh)
                    : null,
                'structure' => $d->agent?->structure?->nom ?? 'Sans structure',
            ],
        );

        $declarations = fn (string $nature, Collection $lignes) => $lignes->map(
            fn ($d) => [
                'nature' => $nature,
                'depot' => $d->created_at,
                'statut' => $this->categorie($d->statut, $d->motif_rejet),
                'cloture' => $d->validated_at,
                'structure' => $d->agent?->structure?->nom ?? 'Sans structure',
            ],
        );

        return $permissions
            ->concat(
                $declarations('naissance', DeclarationNaissance::with('agent.structure')->get()),
            )
            ->concat($declarations('deces', DeclarationDeces::with('agent.structure')->get()));
    }

    /**
     * Range un statut de dossier dans l'une des quatre catégories du tableau de bord : validée,
     * rejetée, à corriger ou en attente.
     */
    private function categorie(string $statut, ?string $motifRejet): string
    {
        return match ($statut) {
            'VALIDEE' => 'validee',
            'REJETEE' => 'rejetee',
            'RETOUR_CORRECTION' => 'a_corriger',
            'ARCHIVEE' => $motifRejet ? 'rejetee' : 'validee',
            default => 'en_attente',
        };
    }

    /** Délai moyen dépôt → décision finale, en jours (une décimale), sur les dossiers clos. */
    private function delaiMoyen(Collection $dossiers): ?float
    {
        $delais = $dossiers
            ->whereNotNull('cloture')
            ->map(fn (array $d) => max(0, $d['depot']->diffInSeconds($d['cloture'])) / 86400);

        return $delais->isEmpty() ? null : round($delais->avg(), 1);
    }

    /** Dépôts des 12 derniers mois (mois sans dossier inclus), ventilés par nature. */
    private function parMois(Collection $dossiers): Collection
    {
        $debut = now()->startOfMonth()->subMonths(11);

        return collect(range(0, 11))->map(function (int $i) use ($debut, $dossiers) {
            $mois = $debut->copy()->addMonths($i);
            $duMois = $dossiers->filter(
                fn (array $d) => $d['depot']?->format('Y-m') === $mois->format('Y-m'),
            );

            return ['mois' => $mois->format('Y-m'), 'total' => $duMois->count()] +
                collect(self::NATURES)
                    ->map(
                        fn ($libelle, string $nature) => $duMois->where('nature', $nature)->count(),
                    )
                    ->all();
        });
    }
}
