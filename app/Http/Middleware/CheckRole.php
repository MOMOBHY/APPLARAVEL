<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole(...$roles)) {
            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Accès non autorisé.'], 403);
            }
            abort(403, 'Accès non autorisé.');
        }

        return $next($request);
    }
}
