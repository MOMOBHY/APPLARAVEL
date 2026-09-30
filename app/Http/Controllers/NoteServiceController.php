<?php

namespace App\Http\Controllers;

use App\Models\NoteService;
use App\Services\NoteWorkflowService;
use App\Services\PieceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NoteServiceController extends Controller
{
    /** Consultation : agents destinataires (diffusées/archivées), émetteur, admin, secrétaire. */
    public function index(Request $request)
    {
        $query = NoteWorkflowService::visiblesPour($request->user())
            ->with(['structures', 'signataire'])
            ->latest();

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'data' => $query->paginate(20)]);
        }

        return view('notes.index', ['notes' => $query->paginate(20)]);
    }

    /** Détail d'une note de service avec son historique, si l'utilisateur a le droit de la voir. */
    public function show(Request $request, NoteService $note)
    {
        abort_unless(
            NoteWorkflowService::visiblesPour($request->user())->whereKey($note->id)->exists(),
            403,
            'Accès non autorisé à cette note.',
        );

        $note->load(['structures', 'signataire', 'historique']);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Étape 1 — rédaction (brouillon) par le secrétariat. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'objet' => ['required', 'string', 'min:5'],
            'contenu' => ['nullable', 'string'],
            'date_emission' => ['nullable', 'date'],
            'structure_ids' => ['nullable', 'array'],
            'structure_ids.*' => ['exists:structures,id'],
            'fichier' => [
                'nullable',
                'file',
                'mimes:'.implode(',', PieceService::MIMES),
                'max:'.PieceService::MAX_KO,
            ],
        ]);

        $note = NoteWorkflowService::rediger($request->user()->agent, $data, null);

        if ($request->hasFile('fichier')) {
            $piece = PieceService::deposer(
                $request->file('fichier'),
                'note',
                $note->id,
                $note->numero_reference,
                $request->user()->agent,
            );
            $note->update(['fichier_path' => $piece->chemin_stockage]);
        }

        return response()->json(
            ['status' => 'success', 'data' => $note->refresh()->load('structures')],
            201,
        );
    }

    /** Envoi aux directeurs par le secrétariat (brouillon ou note reprise). */
    public function envoyer(NoteService $note)
    {
        $note = NoteWorkflowService::envoyerAuxDirecteurs($note, request()->user()->agent);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Étape 2 — saisie / mise en forme / enregistrement (secrétaire). */
    public function saisir(Request $request, NoteService $note)
    {
        $data = $request->validate([
            'contenu' => ['nullable', 'string', 'min:5'],
            'numero_reference' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('notes_service', 'numero_reference')->ignore($note->id),
            ],
            'structure_ids' => ['nullable', 'array'],
            'structure_ids.*' => ['exists:structures,id'],
            'fichier' => [
                'nullable',
                'file',
                'mimes:'.implode(',', PieceService::MIMES),
                'max:'.PieceService::MAX_KO,
            ],
        ]);

        NoteWorkflowService::exigerSaisissable($note);

        if ($request->hasFile('fichier')) {
            $piece = PieceService::deposer(
                $request->file('fichier'),
                'note',
                $note->id,
                $note->numero_reference,
                $request->user()->agent,
            );
            $data['fichier_path'] = $piece->chemin_stockage;
        }

        $note = NoteWorkflowService::saisir($note, $request->user()->agent, $data);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Reprise d'une note refusée par le secrétariat (correction sans changer d'étape). */
    public function reprendre(Request $request, NoteService $note)
    {
        $data = $request->validate([
            'objet' => ['nullable', 'string', 'min:5', 'max:200'],
            'contenu' => ['nullable', 'string'],
            'structure_ids' => ['nullable', 'array'],
            'structure_ids.*' => ['exists:structures,id'],
        ]);
        $note = NoteWorkflowService::reprendre($note, $request->user()->agent, $data);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Étape 3 — validation par un directeur (la première validation l'emporte). */
    public function valider(NoteService $note)
    {
        $note = NoteWorkflowService::valider($note, request()->user()->agent);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Refus motivé de validation par un directeur (retour au secrétariat). */
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

    /** Diffusion directe d'une note du DRH vers les agents de sa direction. */
    public function diffuserDirectement(NoteService $note)
    {
        $note = NoteWorkflowService::diffuserDirectement($note, request()->user()->agent);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Archive une note validée ou diffusée. La note reste consultable. */
    public function archiver(NoteService $note)
    {
        $note = NoteWorkflowService::archiver($note, request()->user()->agent);

        return response()->json(['status' => 'success', 'data' => $note]);
    }

    /** Étape 5 — liste exacte des structures et agents destinataires. */
    public function destinataires(NoteService $note)
    {
        return response()->json(
            ['status' => 'success'] + NoteWorkflowService::destinataires($note),
        );
    }
}
