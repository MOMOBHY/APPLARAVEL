<?php

namespace App\Http\Controllers;

use App\Models\DemandePermission;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /** Administrateur : liste paginée des comptes avec leurs rôles et leur structure. */
    public function users()
    {
        return response()->json([
            'status' => 'success',
            'data' => User::with(['roles', 'agent.structure'])->paginate(20),
        ]);
    }

    /**
     * Administrateur ou DRH : recherche de demandes de permission par référence, motif, nom ou
     * matricule (50 résultats au plus).
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $resultats = DemandePermission::with(['agent', 'type'])
            ->when(
                $q !== '',
                fn ($query) => $query
                    ->where('code_dossier', 'like', "%{$q}%")
                    ->orWhere('motif', 'like', "%{$q}%")
                    ->orWhereHas(
                        'agent',
                        fn ($a) => $a
                            ->where('nom', 'like', "%{$q}%")
                            ->orWhere('matricule', 'like', "%{$q}%"),
                    ),
            )
            ->limit(50)
            ->get();

        return response()->json(['status' => 'success', 'data' => $resultats]);
    }
}
