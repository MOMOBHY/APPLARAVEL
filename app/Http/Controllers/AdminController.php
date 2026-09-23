<?php

namespace App\Http\Controllers;

use App\Models\DemandePermission;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function users()
    {
        return response()->json([
            'status' => 'success',
            'data' => User::with(['roles', 'agent.structure'])->paginate(20),
        ]);
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $resultats = DemandePermission::with(['agent', 'type'])
            ->when($q !== '', fn ($query) => $query->where('code_dossier', 'like', "%{$q}%")
                ->orWhere('motif', 'like', "%{$q}%")
                ->orWhereHas('agent', fn ($a) => $a->where('nom', 'like', "%{$q}%")->orWhere('matricule', 'like', "%{$q}%")))
            ->limit(50)->get();

        return response()->json(['status' => 'success', 'data' => $resultats]);
    }
}
