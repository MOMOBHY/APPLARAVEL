<?php

namespace App\Http\Controllers;

use App\Models\DeclarationHistorique;
use App\Models\DeclarationNaissance;
use App\Services\EtatCivilService;
use App\Services\PieceService;
use Illuminate\Http\Request;

class NaissanceController extends Controller
{
    /** Tableau des déclarations de naissance (séparé des permissions), avec ses filtres (statut, dates,
     * structure, agent) et sa recherche. */
    public function index(Request $request)
    {
        $query = DeclarationNaissance::with('agent.structure')->latest();
        $user = $request->user();
        $estResponsable = $user->hasRole(...EtatCivilService::ROLES_RESPONSABLE);
        if (! $user->hasRole(...EtatCivilService::ROLES_SUIVI) && ! $estResponsable) {
            $query->where('agent_id', $user->agent_id);
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->input('statut'));
        }
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }
        if ($request->filled('date_debut')) {
            $query->whereDate('created_at', '>=', $request->input('date_debut'));
        }
        if ($request->filled('date_fin')) {
            $query->whereDate('created_at', '<=', $request->input('date_fin'));
        }
        if ($request->filled('structure_id')) {
            $query->whereHas('agent', fn ($q) => $q->where('structure_id', $request->input('structure_id')));
        }
        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->input('agent_id'));
        }
        if ($request->filled('search')) {
            $s = '%'.trim($request->input('search')).'%';
            $query->where(function ($q) use ($s) {
                $q->where('code_dossier', 'like', $s)
                    ->orWhere('nom_enfant', 'like', $s)
                    ->orWhere('prenom_enfant', 'like', $s)
                    ->orWhereHas('agent', fn ($qq) => $qq->where('matricule', 'like', $s)
                        ->orWhere('nom', 'like', $s)->orWhere('prenom', 'like', $s));
            });
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'data' => $query->paginate(20)]);
        }

        return view('naissances.index', ['naissances' => $query->paginate(20)]);
    }

    /** Détail d'une déclaration de naissance avec son historique. Réservé au déclarant, au gestionnaire
     * RH, au responsable concerné, à la DRH et à l'administrateur. */
    public function show(Request $request, DeclarationNaissance $naissance)
    {
        $user = $request->user();
        abort_unless(
            $naissance->agent_id === $user->agent_id ||
                $user->hasRole(...EtatCivilService::ROLES_SUIVI) ||
                $user->hasRole(...EtatCivilService::ROLES_RESPONSABLE),
            403,
            'Accès non autorisé à cette déclaration.',
        );

        $this->historique($naissance);

        return response()->json(['status' => 'success', 'data' => $naissance]);
    }

    /** Dépôt d'une naissance : « Brouillon » (sans transmission) ou « Soumettre » (EN_ATTENTE_RH,
     * gestionnaire RH notifié). L'extrait d'acte est exigé pour soumettre. */
    public function store(Request $request)
    {
        $soumettre = filter_var($request->input('soumettre', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $data = $request->validate([
            'nom_enfant' => [$soumettre ? 'required' : 'nullable', 'string', 'max:100'],
            'prenom_enfant' => [$soumettre ? 'required' : 'nullable', 'string', 'max:150'],
            'date_naissance_enfant' => [$soumettre ? 'required' : 'nullable', 'date', 'before_or_equal:today'],
            'lieu_naissance_enfant' => [$soumettre ? 'required' : 'nullable', 'string', 'max:255'],
            'extrait' => [
                $soumettre ? 'required' : 'nullable',
                'file',
                'mimes:'.implode(',', PieceService::MIMES),
                'max:'.PieceService::MAX_KO,
            ],
            'soumettre' => ['nullable', 'boolean'],
        ]);
        $data['soumettre'] = $soumettre;

        $agent = $request->user()->agent;
        $piecePath = null;
        $piece = null;
        if ($request->hasFile('extrait')) {
            $piece = PieceService::deposer($request->file('extrait'), 'naissance', null, null, $agent);
            $piecePath = $piece->chemin_stockage;
        }
        $declaration = EtatCivilService::declarer(
            'NAISSANCE',
            $agent,
            $data + [
                'nom_enfant' => $data['nom_enfant'] ?? '',
                'prenom_enfant' => $data['prenom_enfant'] ?? '',
                'date_naissance_enfant' => $data['date_naissance_enfant'] ?? now()->format('Y-m-d'),
                'lieu_naissance_enfant' => $data['lieu_naissance_enfant'] ?? '',
            ],
            $piecePath,
        );
        if ($piece) {
            $piece->update([
                'dossier_id' => $declaration->id,
                'reference_dossier' => $declaration->code_dossier,
            ]);
        }

        return response()->json(['status' => 'success', 'data' => $declaration->refresh()], 201);
    }

    /** Soumet au gestionnaire RH une déclaration de naissance restée en brouillon. Seul le déclarant peut
     * le faire. */
    public function soumettre(Request $request, DeclarationNaissance $naissance)
    {
        $declaration = EtatCivilService::soumettreBrouillon(
            'NAISSANCE',
            $naissance,
            $request->user()->agent,
        );

        return response()->json(['status' => 'success', 'data' => $declaration]);
    }

    /** Gestionnaire RH : contrôle d'une naissance en EN_ATTENTE_RH ; « conforme » transmet au DRH, «
     * retourner » renvoie à l'agent avec un motif. */
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

    /** Agent : corrige et renvoie une déclaration de naissance retournée. La pièce officielle (extrait
     * d’acte de naissance) reste obligatoire. */
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

    /** DRH : validation finale (EN_ATTENTE_DRH → VALIDEE, dossier statutaire mis à jour) ou rejet final
     * (→ REJETEE, motif obligatoire, notification à l'agent). */
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

    /** DRH : archive une déclaration de naissance clôturée (validée ou rejetée). Le dossier reste
     * consultable. */
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
