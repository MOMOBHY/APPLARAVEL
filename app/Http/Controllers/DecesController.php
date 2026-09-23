<?php

namespace App\Http\Controllers;

use App\Models\DeclarationDeces;
use App\Models\DeclarationHistorique;
use App\Services\EtatCivilService;
use App\Services\PieceService;
use Illuminate\Http\Request;

class DecesController extends Controller
{
    public function index(Request $request)
    {
        $query = DeclarationDeces::with('agent')->latest();
        if (! $request->user()->hasRole('ROLE_GESTIONNAIRE_RH', 'ROLE_DRH', 'ROLE_ADMIN_DSI')) {
            $query->where('agent_id', $request->user()->agent_id);
        }

        return response()->json(['status' => 'success', 'data' => $query->paginate(20)]);
    }

    public function show(DeclarationDeces $deces)
    {
        $deces->setAttribute('historique', DeclarationHistorique::where('type_dossier', 'DECES')
            ->where('dossier_id', $deces->id)->latest('id')->get());

        return response()->json(['status' => 'success', 'data' => $deces]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nom_defunt' => ['required', 'string', 'max:100'],
            'prenom_defunt' => ['required', 'string', 'max:150'],
            'lien_parente' => ['required', 'in:ascendant,descendant,conjoint'],
            'date_deces' => ['required', 'date', 'before_or_equal:today'],
            'lieu_deces' => ['required', 'string', 'max:255'],
            'certificat' => ['required', 'file', 'mimes:'.implode(',', PieceService::MIMES), 'max:'.PieceService::MAX_KO],
            'soumettre' => ['nullable', 'boolean'],
        ]);

        $agent = $request->user()->agent;
        $piece = PieceService::deposer($request->file('certificat'), 'deces', null, null, $agent);
        $declaration = EtatCivilService::declarer('DECES', $agent, $data, $piece->chemin_stockage);
        $piece->update(['dossier_id' => $declaration->id, 'reference_dossier' => $declaration->code_dossier]);

        return response()->json(['status' => 'success', 'data' => $declaration->refresh()], 201);
    }

    public function soumettre(Request $request, DeclarationDeces $deces)
    {
        $declaration = EtatCivilService::soumettreBrouillon('DECES', $deces, $request->user()->agent);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    public function controler(Request $request, DeclarationDeces $deces)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:conforme,retourner'],
            'motif' => ['nullable', 'string', 'min:3'],
        ]);

        $declaration = EtatCivilService::controler('DECES', $deces, $request->user()->agent, $data['decision'], $data['motif'] ?? null);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    public function corriger(Request $request, DeclarationDeces $deces)
    {
        $data = $request->validate([
            'nom_defunt' => ['nullable', 'string', 'max:100'],
            'prenom_defunt' => ['nullable', 'string', 'max:150'],
            'lien_parente' => ['nullable', 'in:ascendant,descendant,conjoint'],
            'date_deces' => ['nullable', 'date', 'before_or_equal:today'],
            'lieu_deces' => ['nullable', 'string', 'max:255'],
            'certificat' => ['nullable', 'file', 'mimes:'.implode(',', PieceService::MIMES), 'max:'.PieceService::MAX_KO],
        ]);

        $piecePath = null;
        if ($request->hasFile('certificat')) {
            $piece = PieceService::deposer($request->file('certificat'), 'deces', $deces->id, $deces->code_dossier, $request->user()->agent);
            $piecePath = $piece->chemin_stockage;
        }

        $declaration = EtatCivilService::corriger('DECES', $deces, $request->user()->agent, $data, $piecePath);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    public function valider(Request $request, DeclarationDeces $deces)
    {
        $data = $request->validate([
            'valide' => ['required', 'boolean'],
            'motif' => ['nullable', 'string', 'min:3'],
        ]);

        $declaration = EtatCivilService::trancher('DECES', $deces, $request->user()->agent, (bool) $data['valide'], $data['motif'] ?? null);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    public function archiver(Request $request, DeclarationDeces $deces)
    {
        $declaration = EtatCivilService::archiver('DECES', $deces, $request->user()->agent);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }
}
