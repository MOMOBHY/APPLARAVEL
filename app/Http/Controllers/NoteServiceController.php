<?php

namespace App\Http\Controllers;

use App\Models\NoteService;
use App\Services\NoteWorkflowService;
use App\Services\PieceService;
use Illuminate\Http\Request;

class NoteServiceController extends Controller
{
    /** Consultation : agents destinataires (diffusées/archivées), émetteur, admin, secrétaire. */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = NoteService::with(['structures', 'signataire'])->latest();

        $interne = $user->hasRole('ROLE_ADMIN_DSI', 'ROLE_DRH', 'ROLE_DIRECTEUR', 'ROLE_SOUS_DIRECTEUR', 'ROLE_SECRETAIRE');
        if (! $interne) {
            $query->whereIn('statut', [NoteService::DIFFUSEE, NoteService::ARCHIVEE])
                ->whereHas('structures', fn ($q) => $q->where('structures.id', $user->agent->structure_id));
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'data' => $query->paginate(20)]);
        }

        return view('notes.index', ['notes' => $query->paginate(20)]);
    }

    public function show(NoteService $note)
    {
        $note->load(['structures', 'signataire', 'historique']);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Étape 1 — rédaction (brouillon) par une autorité habilitée. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'objet' => ['required', 'string', 'min:5'],
            'contenu' => ['nullable', 'string'],
            'date_emission' => ['nullable', 'date'],
            'structure_ids' => ['nullable', 'array'],
            'structure_ids.*' => ['exists:structures,id'],
            'fichier' => ['nullable', 'file', 'mimes:'.implode(',', PieceService::MIMES), 'max:'.PieceService::MAX_KO],
        ]);

        $note = NoteWorkflowService::rediger($request->user()->agent, $data, null);

        if ($request->hasFile('fichier')) {
            $piece = PieceService::deposer($request->file('fichier'), 'note', $note->id, $note->numero_reference, $request->user()->agent);
            $note->update(['fichier_path' => $piece->chemin_stockage]);
        }

        return response()->json(['status' => 'success', 'data' => $note->refresh()->load('structures')], 201);
    }

    /** Transmission au secrétariat par l'autorité émettrice. */
    public function transmettre(NoteService $note)
    {
        $note = NoteWorkflowService::transmettreSecretariat($note, request()->user()->agent);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Étape 2 — saisie / mise en forme / enregistrement (secrétaire). */
    public function saisir(Request $request, NoteService $note)
    {
        $data = $request->validate([
            'contenu' => ['nullable', 'string', 'min:5'],
            'numero_reference' => ['nullable', 'string', 'max:80'],
            'structure_ids' => ['nullable', 'array'],
            'structure_ids.*' => ['exists:structures,id'],
            'fichier' => ['nullable', 'file', 'mimes:'.implode(',', PieceService::MIMES), 'max:'.PieceService::MAX_KO],
        ]);

        if ($request->hasFile('fichier')) {
            $piece = PieceService::deposer($request->file('fichier'), 'note', $note->id, $note->numero_reference, $request->user()->agent);
            $data['fichier_path'] = $piece->chemin_stockage;
        }

        $note = NoteWorkflowService::saisir($note, $request->user()->agent, $data);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Étape 3 — validation par l'autorité émettrice. */
    public function valider(NoteService $note)
    {
        $note = NoteWorkflowService::valider($note, request()->user()->agent);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Refus motivé de validation par l'autorité émettrice. */
    public function refuser(Request $request, NoteService $note)
    {
        $data = $request->validate(['motif' => ['required', 'string', 'min:3']]);
        $note = NoteWorkflowService::refuser($note, request()->user()->agent, $data['motif']);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Étape 4 — diffusion (note validée uniquement). */
    public function diffuser(NoteService $note)
    {
        $note = NoteWorkflowService::diffuser($note, request()->user()->agent);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    public function archiver(NoteService $note)
    {
        $note = NoteWorkflowService::archiver($note, request()->user()->agent);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Étape 5 — liste exacte des structures et agents destinataires. */
    public function destinataires(NoteService $note)
    {
        return response()->json(['status' => 'success'] + NoteWorkflowService::destinataires($note));
    }
}
