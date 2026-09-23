<?php

namespace App\Http\Controllers;

use App\Models\PieceJointe;
use App\Services\PieceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PieceController extends Controller
{
    /** Téléchargement contrôlé : jamais d'accès direct sans droits. */
    public function telecharger(Request $request, PieceJointe $piece)
    {
        abort_unless(PieceService::autorise($request->user(), $piece), 403, 'Accès non autorisé à cette pièce.');
        abort_unless(Storage::disk('local')->exists($piece->chemin_stockage), 404, 'Fichier introuvable.');

        return Storage::disk('local')->download($piece->chemin_stockage, $piece->nom_fichier);
    }
}
