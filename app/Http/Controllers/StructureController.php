<?php

namespace App\Http\Controllers;

use App\Models\Structure;
use Illuminate\Support\Str;

/** Annuaire des structures officielles du ministère, classées de A à Z. */
class StructureController extends Controller
{
    /**
     * Annuaire des structures officielles du ministère classées de A à Z, avec leur rattachement et le
     * nombre d'agents.
     */
    public function index()
    {
        $structures = Structure::with('parent:id,nom,sigle')
            ->withCount('agents')
            ->where('officielle', true)
            ->get()
            ->sortBy(fn (Structure $s) => Str::lower(Str::ascii($s->nom)), SORT_NATURAL)
            ->values()
            ->map(
                fn (Structure $s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'nom' => $s->nom,
                    'sigle' => $s->sigle,
                    'type' => $s->type,
                    'rattachement' => $s->parent?->nom,
                    'rattachement_sigle' => $s->parent?->sigle,
                    'effectif' => $s->agents_count,
                ],
            );

        return response()->json([
            'status' => 'success',
            'total' => $structures->count(),
            'structures' => $structures,
        ]);
    }
}
