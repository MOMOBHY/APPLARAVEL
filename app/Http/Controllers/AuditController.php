<?php

namespace App\Http\Controllers;

use App\Models\JournalAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Journal d'audit (administrateur) : entrées, sorties et actions de tous les utilisateurs. */
class AuditController extends Controller
{
    /**
     * Administrateur : page du journal d'audit avec les indicateurs du jour (connexions, déconnexions,
     * échecs, actions, utilisateurs actifs).
     */
    public function index(Request $request)
    {
        $lignes = $this->filtrer($request)->latest('id')->paginate(40);

        $aujourdhui = now()->startOfDay();
        $connexions = JournalAudit::where('categorie', JournalAudit::CONNEXION);

        return response()->json([
            'status' => 'success',
            'resume' => [
                'connexions_aujourdhui' => (clone $connexions)
                    ->where('action', 'CONNEXION')
                    ->where('created_at', '>=', $aujourdhui)
                    ->count(),
                'deconnexions_aujourdhui' => (clone $connexions)
                    ->where('action', 'DECONNEXION')
                    ->where('created_at', '>=', $aujourdhui)
                    ->count(),
                'echecs_aujourdhui' => (clone $connexions)
                    ->where('reussi', false)
                    ->where('created_at', '>=', $aujourdhui)
                    ->count(),
                'actions_aujourdhui' => JournalAudit::where(
                    'categorie',
                    '!=',
                    JournalAudit::CONNEXION,
                )
                    ->where('created_at', '>=', $aujourdhui)
                    ->count(),
                'utilisateurs_actifs_24h' => JournalAudit::where(
                    'created_at',
                    '>=',
                    now()->subDay(),
                )
                    ->whereNotNull('user_id')
                    ->distinct()
                    ->count('user_id'),
            ],
            'lignes' => collect($lignes->items())
                ->map(fn (JournalAudit $l) => $this->ligne($l))
                ->all(),
            'page' => $lignes->currentPage(),
            'pages' => $lignes->lastPage(),
            'total' => $lignes->total(),
        ]);
    }

    /** Administrateur : exporte le journal filtré en CSV (5 000 lignes au plus), lisible dans Excel. */
    public function exporter(Request $request)
    {
        $requete = $this->filtrer($request)->latest('id')->limit(5000);

        return response()->streamDownload(
            function () use ($requete) {
                $sortie = fopen('php://output', 'w');
                fwrite($sortie, "\xEF\xBB\xBF");
                fputcsv(
                    $sortie,
                    [
                        'Date',
                        'Matricule',
                        'Nom',
                        'Rôle',
                        'Catégorie',
                        'Action',
                        'Détail',
                        'Référence',
                        'Résultat',
                        'Adresse IP',
                    ],
                    ';',
                );
                foreach ($requete->cursor() as $l) {
                    fputcsv(
                        $sortie,
                        [
                            $l->created_at?->format('d/m/Y H:i:s'),
                            $l->matricule,
                            $l->nom,
                            $l->role,
                            $l->categorie,
                            $l->action,
                            $l->description,
                            $l->reference,
                            $l->reussi ? 'Réussi' : 'Échec',
                            $l->ip,
                        ],
                        ';',
                    );
                }
                fclose($sortie);
            },
            'journal-audit-'.now()->format('Ymd-His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Applique les filtres du journal : catégorie, échecs seulement, période et recherche (matricule,
     * nom, référence, description).
     */
    private function filtrer(Request $request): Builder
    {
        $q = trim((string) $request->query('q', ''));

        return JournalAudit::query()
            ->when($request->query('categorie'), fn ($r, $c) => $r->where('categorie', $c))
            ->when($request->query('reussi') === '0', fn ($r) => $r->where('reussi', false))
            ->when(
                $request->query('du'),
                fn ($r, $d) => $r->where('created_at', '>=', $d.' 00:00:00'),
            )
            ->when(
                $request->query('au'),
                fn ($r, $d) => $r->where('created_at', '<=', $d.' 23:59:59'),
            )
            ->when(
                $q !== '',
                fn ($r) => $r->where(
                    fn ($w) => $w
                        ->where('matricule', 'like', "%{$q}%")
                        ->orWhere('nom', 'like', "%{$q}%")
                        ->orWhere('reference', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%"),
                ),
            );
    }

    /** Met une ligne du journal au format renvoyé à l'interface. */
    private function ligne(JournalAudit $l): array
    {
        return [
            'id' => $l->id,
            'date' => $l->created_at?->format('Y-m-d H:i:s'),
            'matricule' => $l->matricule,
            'nom' => $l->nom,
            'role' => $l->role,
            'categorie' => $l->categorie,
            'action' => $l->action,
            'description' => $l->description,
            'reference' => $l->reference,
            'reussi' => $l->reussi,
            'ip' => $l->ip,
        ];
    }
}
