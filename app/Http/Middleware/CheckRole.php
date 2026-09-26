<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Laisse passer la requête si l'utilisateur possède au moins un des rôles demandés ; sinon répond
     * 403 (JSON) ou redirige vers la connexion.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole(...$roles)) {
            if ($request->expectsJson()) {
                return response()->json(
                    ['status' => 'error', 'message' => 'Accès non autorisé.'],
                    403,
                );
            }
            abort(403, 'Accès non autorisé.');
        }

        return $next($request);
    }
}
