<?php

namespace App\Http\Controllers;

use App\Models\DeclarationHistorique;
use App\Models\DeclarationNaissance;
use App\Services\EtatCivilService;
use App\Services\PieceService;
use Illuminate\Http\Request;

class NaissanceController extends Controller
{
    public function index(Request $request)
    {
        $query = DeclarationNaissance::with('agent')->latest();
        if (! $request->user()->hasRole('ROLE_GESTIONNAIRE_RH', 'ROLE_DRH', 'ROLE_ADMIN_DSI')) {
            $query->where('agent_id', $request->user()->agent_id);
        }

        return response()->json(['status' => 'success', 'data' => $query->paginate(20)]);
    }

    public function show(DeclarationNaissance $naissance)
    {
        $this->historique($naissance);

        return response()->json(['status' => 'success', 'data' => $naissance]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nom_enfant' => ['required', 'string', 'max:100'],
            'prenom_enfant' => ['required', 'string', 'max:150'],
            'date_naissance_enfant' => ['required', 'date', 'before_or_equal:today'],
            'lieu_naissance_enfant' => ['required', 'string', 'max:255'],
            'extrait' => ['required', 'file', 'mimes:'.implode(',', PieceService::MIMES), 'max:'.PieceService::MAX_KO],
            'soumettre' => ['nullable', 'boolean'],
        ]);

        $agent = $request->user()->agent;
        $piece = PieceService::deposer($request->file('extrait'), 'naissance', null, null, $agent);
        $declaration = EtatCivilService::declarer('NAISSANCE', $agent, $data, $piece->chemin_stockage);
        $piece->update(['dossier_id' => $declaration->id, 'reference_dossier' => $declaration->code_dossier]);

        return response()->json(['status' => 'success', 'data' => $declaration->refresh()], 201);
    }

    public function soumettre(Request $request, DeclarationNaissance $naissance)
    {
        $declaration = EtatCivilService::soumettreBrouillon('NAISSANCE', $naissance, $request->user()->agent);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    public function controler(Request $request, DeclarationNaissance $naissance)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:conforme,retourner'],
            'motif' => ['nullable', 'string', 'min:3'],
        ]);

        $declaration = EtatCivilService::controler('NAISSANCE', $naissance, $request->user()->agent, $data['decision'], $data['motif'] ?? null);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    public function corriger(Request $request, DeclarationNaissance $naissance)
    {
        $data = $request->validate([
            'nom_enfant' => ['nullable', 'string', 'max:100'],
            'prenom_enfant' => ['nullable', 'string', 'max:150'],
            'date_naissance_enfant' => ['nullable', 'date', 'before_or_equal:today'],
            'lieu_naissance_enfant' => ['nullable', 'string', 'max:255'],
            'extrait' => ['nullable', 'file', 'mimes:'.implode(',', PieceService::MIMES), 'max:'.PieceService::MAX_KO],
        ]);

        $piecePath = null;
        if ($request->hasFile('extrait')) {
            $piece = PieceService::deposer($request->file('extrait'), 'naissance', $naissance->id, $naissance->code_dossier, $request->user()->agent);
            $piecePath = $piece->chemin_stockage;
        }

        $declaration = EtatCivilService::corriger('NAISSANCE', $naissance, $request->user()->agent, $data, $piecePath);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    public function valider(Request $request, DeclarationNaissance $naissance)
    {
        $data = $request->validate([
            'valide' => ['required', 'boolean'],
            'motif' => ['nullable', 'string', 'min:3'],
        ]);

        $declaration = EtatCivilService::trancher('NAISSANCE', $naissance, $request->user()->agent, (bool) $data['valide'], $data['motif'] ?? null);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    public function archiver(Request $request, DeclarationNaissance $naissance)
    {
        $declaration = EtatCivilService::archiver('NAISSANCE', $naissance, $request->user()->agent);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    private function historique(DeclarationNaissance $naissance): void
    {
        $naissance->setAttribute('historique', DeclarationHistorique::where('type_dossier', 'NAISSANCE')
            ->where('dossier_id', $naissance->id)->latest('id')->get());
    }
}
