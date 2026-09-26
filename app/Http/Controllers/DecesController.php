<?php

namespace App\Http\Controllers;

use App\Models\DeclarationDeces;
use App\Models\DeclarationHistorique;
use App\Services\EtatCivilService;
use App\Services\PieceService;
use Illuminate\Http\Request;

class DecesController extends Controller
{
    /**
     * Liste des déclaration de décèss. L'agent ne voit que les siennes ; le gestionnaire RH, le DRH et
     * l'administrateur voient toutes.
     */
    public function index(Request $request)
    {
        $query = DeclarationDeces::with('agent')->latest();
        if (! $request->user()->hasRole(...EtatCivilService::ROLES_SUIVI)) {
            $query->where('agent_id', $request->user()->agent_id);
        }

        return response()->json(['status' => 'success', 'data' => $query->paginate(20)]);
    }

    /**
     * Détail d'une déclaration de décès avec son historique. Réservé au déclarant et aux rôles de
     * suivi.
     */
    public function show(Request $request, DeclarationDeces $deces)
    {
        $user = $request->user();
        abort_unless(
            $deces->agent_id === $user->agent_id ||
                $user->hasRole(...EtatCivilService::ROLES_SUIVI),
            403,
            'Accès non autorisé à cette déclaration.',
        );

        $deces->setAttribute(
            'historique',
            DeclarationHistorique::where('type_dossier', 'DECES')
                ->where('dossier_id', $deces->id)
                ->latest('id')
                ->get(),
        );

        return response()->json(['status' => 'success', 'data' => $deces]);
    }

    /**
     * Dépose une déclaration de décès. Le fichier (certificat de décès) est obligatoire ; sans demande
     * de brouillon, le dossier part tout de suite au gestionnaire RH.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nom_defunt' => ['required', 'string', 'max:100'],
            'prenom_defunt' => ['required', 'string', 'max:150'],
            'lien_parente' => ['required', 'in:ascendant,descendant,conjoint'],
            'date_deces' => ['required', 'date', 'before_or_equal:today'],
            'lieu_deces' => ['required', 'string', 'max:255'],
            'certificat' => [
                'required',
                'file',
                'mimes:'.implode(',', PieceService::MIMES),
                'max:'.PieceService::MAX_KO,
            ],
            'soumettre' => ['nullable', 'boolean'],
        ]);

        $agent = $request->user()->agent;
        $piece = PieceService::deposer($request->file('certificat'), 'deces', null, null, $agent);
        $declaration = EtatCivilService::declarer('DECES', $agent, $data, $piece->chemin_stockage);
        $piece->update([
            'dossier_id' => $declaration->id,
            'reference_dossier' => $declaration->code_dossier,
        ]);

        return response()->json(['status' => 'success', 'data' => $declaration->refresh()], 201);
    }

    /**
     * Soumet au gestionnaire RH une déclaration de décès restée en brouillon. Seul le déclarant peut
     * le faire.
     */
    public function soumettre(Request $request, DeclarationDeces $deces)
    {
        $declaration = EtatCivilService::soumettreBrouillon(
            'DECES',
            $deces,
            $request->user()->agent,
        );

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    /**
     * Gestionnaire RH : contrôle les pièces d'une déclaration de décès. Décision « conforme »
     * (transmise au DRH) ou « retourner » (retour à l'agent, motif obligatoire).
     */
    public function controler(Request $request, DeclarationDeces $deces)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:conforme,retourner'],
            'motif' => ['nullable', 'string', 'min:3'],
        ]);

        $declaration = EtatCivilService::controler(
            'DECES',
            $deces,
            $request->user()->agent,
            $data['decision'],
            $data['motif'] ?? null,
        );

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    /**
     * Agent : corrige et renvoie une déclaration de décès retournée. La pièce officielle (certificat
     * de décès) reste obligatoire.
     */
    public function corriger(Request $request, DeclarationDeces $deces)
    {
        $data = $request->validate([
            'nom_defunt' => ['nullable', 'string', 'max:100'],
            'prenom_defunt' => ['nullable', 'string', 'max:150'],
            'lien_parente' => ['nullable', 'in:ascendant,descendant,conjoint'],
            'date_deces' => ['nullable', 'date', 'before_or_equal:today'],
            'lieu_deces' => ['nullable', 'string', 'max:255'],
            'certificat' => [
                'nullable',
                'file',
                'mimes:'.implode(',', PieceService::MIMES),
                'max:'.PieceService::MAX_KO,
            ],
        ]);

        EtatCivilService::exigerCorrigeable($deces, $request->user()->agent);

        $piecePath = null;
        if ($request->hasFile('certificat')) {
            $piece = PieceService::deposer(
                $request->file('certificat'),
                'deces',
                $deces->id,
                $deces->code_dossier,
                $request->user()->agent,
            );
            $piecePath = $piece->chemin_stockage;
        }

        $declaration = EtatCivilService::corriger(
            'DECES',
            $deces,
            $request->user()->agent,
            $data,
            $piecePath,
        );

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    /**
     * DRH : décision finale sur une déclaration de décès. Validation, ou rejet avec un motif
     * obligatoire.
     */
    public function valider(Request $request, DeclarationDeces $deces)
    {
        $data = $request->validate([
            'valide' => ['required', 'boolean'],
            'motif' => ['nullable', 'string', 'min:3'],
        ]);

        $declaration = EtatCivilService::trancher(
            'DECES',
            $deces,
            $request->user()->agent,
            (bool) $data['valide'],
            $data['motif'] ?? null,
        );

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    /**
     * DRH : archive une déclaration de décès clôturée (validée ou rejetée). Le dossier reste
     * consultable.
     */
    public function archiver(Request $request, DeclarationDeces $deces)
    {
        $declaration = EtatCivilService::archiver('DECES', $deces, $request->user()->agent);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }
}
