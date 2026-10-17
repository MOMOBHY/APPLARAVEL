<?php

namespace App\Http\Middleware;

use App\Services\PieceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class RefuserPiecesNonRecues
{
    /** Refuse la requête si une pièce jointe n'est pas arrivée entière (au-delà de la limite de taille de
     * PHP, envoi interrompu...). Sans ce contrôle, Laravel la traite comme absente : le dossier serait
     * enregistré sans son justificatif, sans que l'agent le sache. */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (Arr::flatten($request->allFiles()) as $fichier) {
            if ($fichier instanceof UploadedFile && ! $fichier->isValid()) {
                return response()->json(
                    [
                        'status' => 'error',
                        'message' => 'La pièce jointe « '.$fichier->getClientOriginalName().' » n’a pas été '
                            .'reçue en entier : vérifiez qu’elle ne dépasse pas '.(PieceService::MAX_KO / 1024)
                            .' Mo, puis réessayez.',
                    ],
                    422,
                );
            }
        }

        return $next($request);
    }
}
