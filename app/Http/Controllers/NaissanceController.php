<?php

namespace App\Http\Controllers;

use App\Models\DeclarationHistorique;
use App\Models\DeclarationNaissance;
use App\Services\EtatCivilService;
use App\Services\PieceService;
use Illuminate\Http\Request;

class NaissanceController extends Controller
{
    /**
     * Liste des déclaration de naissances. L'agent ne voit que les siennes ; le gestionnaire RH, le
     * DRH et l'administrateur voient toutes.
     */
    public function index(Request $request)
    {
        $query = DeclarationNaissance::with('agent')->latest();
        if (! $request->user()->hasRole(...EtatCivilService::ROLES_SUIVI)) {
            $query->where('agent_id', $request->user()->agent_id);
        }

        return response()->json(['status' => 'success', 'data' => $query->paginate(20)]);
    }

    /**
     * Détail d'une déclaration de naissance avec son historique. Réservé au déclarant et aux rôles de
     * suivi.
     */
    public function show(Request $request, DeclarationNaissance $naissance)
    {
        $user = $request->user();
        abort_unless(
            $naissance->agent_id === $user->agent_id ||
                $user->hasRole(...EtatCivilService::ROLES_SUIVI),
            403,
            'Accès non autorisé à cette déclaration.',
        );

        $this->historique($naissance);

        return response()->json(['status' => 'success', 'data' => $naissance]);
    }

    /**
     * Dépose une déclaration de naissance. Le fichier (extrait d’acte de naissance) est obligatoire ;
     * sans demande de brouillon, le dossier part tout de suite au gestionnaire RH.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nom_enfant' => ['required', 'string', 'max:100'],
            'prenom_enfant' => ['required', 'string', 'max:150'],
            'date_naissance_enfant' => ['required', 'date', 'before_or_equal:today'],
            'lieu_naissance_enfant' => ['required', 'string', 'max:255'],
            'extrait' => [
                'required',
                'file',
                'mimes:'.implode(',', PieceService::MIMES),
                'max:'.PieceService::MAX_KO,
            ],
            'soumettre' => ['nullable', 'boolean'],
        ]);

        $agent = $request->user()->agent;
        $piece = PieceService::deposer($request->file('extrait'), 'naissance', null, null, $agent);
        $declaration = EtatCivilService::declarer(
            'NAISSANCE',
            $agent,
            $data,
            $piece->chemin_stockage,
        );
        $piece->update([
            'dossier_id' => $declaration->id,
            'reference_dossier' => $declaration->code_dossier,
        ]);

        return response()->json(['status' => 'success', 'data' => $declaration->refresh()], 201);
    }

    /**
     * Soumet au gestionnaire RH une déclaration de naissance restée en brouillon. Seul le déclarant
     * peut le faire.
     */
    public function soumettre(Request $request, DeclarationNaissance $naissance)
    {
        $declaration = EtatCivilService::soumettreBrouillon(
            'NAISSANCE',
            $naissance,
            $request->user()->agent,
        );

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    /**
     * Gestionnaire RH : contrôle les pièces d'une déclaration de naissance. Décision « conforme »
     * (transmise au DRH) ou « retourner » (retour à l'agent, motif obligatoire).
     */
    public function controler(Request $request, DeclarationNaissance $naissance)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:conforme,retourner'],
            'motif' => ['nullable', 'string', 'min:3'],
        ]);

        $declaration = EtatCivilService::controler(
            'NAISSANCE',
            $naissance,
            $request->user()->agent,
            $data['decision'],
            $data['motif'] ?? null,
        );

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    /**
     * Agent : corrige et renvoie une déclaration de naissance retournée. La pièce officielle (extrait
     * d’acte de naissance) reste obligatoire.
     */
    public function corriger(Request $request, DeclarationNaissance $naissance)
    {
        $data = $request->validate([
            'nom_enfant' => ['nullable', 'string', 'max:100'],
            'prenom_enfant' => ['nullable', 'string', 'max:150'],
            'date_naissance_enfant' => ['nullable', 'date', 'before_or_equal:today'],
            'lieu_naissance_enfant' => ['nullable', 'string', 'max:255'],
            'extrait' => [
                'nullable',
                'file',
                'mimes:'.implode(',', PieceService::MIMES),
                'max:'.PieceService::MAX_KO,
            ],
        ]);

        EtatCivilService::exigerCorrigeable($naissance, $request->user()->agent);

        $piecePath = null;
        if ($request->hasFile('extrait')) {
            $piece = PieceService::deposer(
                $request->file('extrait'),
                'naissance',
                $naissance->id,
                $naissance->code_dossier,
                $request->user()->agent,
            );
            $piecePath = $piece->chemin_stockage;
        }

        $declaration = EtatCivilService::corriger(
            'NAISSANCE',
            $naissance,
            $request->user()->agent,
            $data,
            $piecePath,
        );

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    /**
     * DRH : décision finale sur une déclaration de naissance. Validation, ou rejet avec un motif
     * obligatoire.
     */
    public function valider(Request $request, DeclarationNaissance $naissance)
    {
        $data = $request->validate([
            'valide' => ['required', 'boolean'],
            'motif' => ['nullable', 'string', 'min:3'],
        ]);

        $declaration = EtatCivilService::trancher(
            'NAISSANCE',
            $naissance,
            $request->user()->agent,
            (bool) $data['valide'],
            $data['motif'] ?? null,
        );

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    /**
     * DRH : archive une déclaration de naissance clôturée (validée ou rejetée). Le dossier reste
     * consultable.
     */
    public function archiver(Request $request, DeclarationNaissance $naissance)
    {
        $declaration = EtatCivilService::archiver('NAISSANCE', $naissance, $request->user()->agent);

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    /** Joint l'historique des étapes de la déclaration au dossier renvoyé. */
    private function historique(DeclarationNaissance $naissance): void
    {
        $naissance->setAttribute(
            'historique',
            DeclarationHistorique::where('type_dossier', 'NAISSANCE')
                ->where('dossier_id', $naissance->id)
                ->latest('id')
                ->get(),
        );
    }
}
