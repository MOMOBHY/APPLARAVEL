<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\NoteDestinataire;
use App\Models\NoteService;
use App\Models\Structure;
use App\Services\NoteWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Notes de service : dépôt du document Word par la secrétaire, validation par son directeur, envoi aux
 * destinataires choisis, consultation par chacun. */
class NoteServiceController extends Controller
{
    /** Messages de validation du document. */
    private const MESSAGES = [
        'fichier.required' => 'Joignez le document Word de la note.',
        'fichier.extensions' => 'Le document doit être un fichier Word (.doc ou .docx).',
        'fichier.mimetypes' => 'Le document doit être un fichier Word (.doc ou .docx).',
        'fichier.max' => 'Le document ne doit pas dépasser 5 Mo.',
        'objet.required' => 'Indiquez l’objet de la note.',
        'date_note.required' => 'Indiquez la date de la note.',
        'motif.required' => 'Le motif du retour est obligatoire.',
    ];

    /** Règles du document Word. */
    private static function regleFichier(bool $obligatoire): array
    {
        return [
            $obligatoire ? 'required' : 'nullable',
            'file',
            'max:'.NoteWorkflowService::MAX_KO,
            'extensions:'.implode(',', NoteWorkflowService::EXTENSIONS),
            'mimetypes:'.implode(',', NoteWorkflowService::MIMES),
        ];
    }

    /** Notes de l'utilisateur connecté : celles qu'il a déposées (secrétaire), celles qu'il doit valider
     * ou qu'il a traitées (directeur) et celles qu'il a reçues (tout agent). */
    public function index(Request $request)
    {
        $user = $request->user();
        $agent = $user->agent;
        $estSecretaire = $user->hasRole('ROLE_SECRETAIRE');
        if (! $agent) {
            return response()->json([
                'status' => 'success',
                'secretaire' => $estSecretaire,
                'directeur' => null,
                'mes_notes' => [],
                'a_valider' => [],
                'traitees' => [],
                'recues' => [],
            ]);
        }

        $avecSuivi = fn () => NoteService::with(['secretaire', 'directeur', 'historique']);
        $mesNotes = $estSecretaire ? $avecSuivi()->where('secretaire_id', $agent->id)->latest('id')->get() : collect();
        $aValider = $avecSuivi()
            ->where('directeur_id', $agent->id)
            ->where('statut', NoteService::EN_ATTENTE_VALIDATION)
            ->latest('transmise_le')
            ->get();
        $traitees = $avecSuivi()
            ->where('directeur_id', $agent->id)
            ->whereIn('statut', [NoteService::A_CORRIGER, NoteService::VALIDEE, NoteService::ENVOYEE])
            ->latest('updated_at')
            ->get();
        $recues = NoteService::with(['secretaire', 'directeur'])
            ->where('statut', NoteService::ENVOYEE)
            ->whereHas('destinataires', fn ($q) => $q->where('agent_id', $agent->id))
            ->latest('envoyee_le')
            ->get();
        $lues = NoteDestinataire::where('agent_id', $agent->id)
            ->whereIn('note_id', $recues->pluck('id'))
            ->whereNotNull('lu_le')
            ->pluck('note_id')
            ->all();
        $directeur = $estSecretaire ? NoteWorkflowService::directeurDe($agent) : null;

        return response()->json([
            'status' => 'success',
            'secretaire' => $estSecretaire,
            'directeur' => $directeur
                ? ['nom' => $directeur->fullName(), 'fonction' => $directeur->user?->roles()->value('libelle')]
                : null,
            'mes_notes' => $mesNotes->map(fn ($n) => $this->format($n, true))->values(),
            'a_valider' => $aValider->map(fn ($n) => $this->format($n, true))->values(),
            'traitees' => $traitees->map(fn ($n) => $this->format($n, true))->values(),
            'recues' => $recues->map(fn ($n) => $this->format($n) + ['lu' => in_array($n->id, $lues, true)])->values(),
        ]);
    }

    /** Dépôt du document Word ; transmis aussitôt au directeur si demandé. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'objet' => ['required', 'string', 'min:3', 'max:255'],
            'date_note' => ['required', 'date'],
            'fichier' => self::regleFichier(true),
            'transmettre' => ['nullable', 'boolean'],
        ], self::MESSAGES);
        $secretaire = $request->user()->agent;
        if ($request->boolean('transmettre') && ! NoteWorkflowService::directeurDe($secretaire)) {
            abort(422, 'Aucun directeur n’est rattaché à votre structure : la note ne peut pas être transmise.');
        }
        $note = NoteWorkflowService::deposer($secretaire, $data['objet'], $data['date_note'], $request->file('fichier'));
        if ($request->boolean('transmettre')) {
            $note = NoteWorkflowService::transmettre($secretaire, $note);
        }

        return response()->json(['status' => 'success', 'data' => $this->format($note->load('directeur'))], 201);
    }

    /** Nouvelle version du document (brouillon ou note retournée par le directeur). */
    public function remplacer(Request $request, NoteService $note)
    {
        $data = $request->validate([
            'fichier' => self::regleFichier(true),
            'objet' => ['nullable', 'string', 'min:3', 'max:255'],
            'date_note' => ['nullable', 'date'],
        ], self::MESSAGES);
        $note = NoteWorkflowService::remplacerDocument(
            $request->user()->agent,
            $note,
            $request->file('fichier'),
            $data['objet'] ?? null,
            $data['date_note'] ?? null,
        );

        return response()->json(['status' => 'success', 'data' => $this->format($note)]);
    }

    public function transmettre(Request $request, NoteService $note)
    {
        $note = NoteWorkflowService::transmettre($request->user()->agent, $note);

        return response()->json(['status' => 'success', 'data' => $this->format($note->load('directeur'))]);
    }

    public function valider(Request $request, NoteService $note)
    {
        $note = NoteWorkflowService::valider($this->agentOuRefus($request), $note);

        return response()->json(['status' => 'success', 'data' => $this->format($note)]);
    }

    public function retourner(Request $request, NoteService $note)
    {
        $data = $request->validate(['motif' => ['required', 'string', 'min:3', 'max:2000']], self::MESSAGES);
        $note = NoteWorkflowService::retourner($this->agentOuRefus($request), $note, $data['motif']);

        return response()->json(['status' => 'success', 'data' => $this->format($note)]);
    }

    /** Envoi de la note validée : tous les agents, des structures et/ou des agents désignés. */
    public function envoyer(Request $request, NoteService $note)
    {
        $data = $request->validate([
            'tous' => ['nullable', 'boolean'],
            'structures' => ['nullable', 'array'],
            'structures.*' => ['integer', 'exists:structures,id'],
            'agents' => ['nullable', 'array'],
            'agents.*' => ['integer', 'exists:agents,id'],
        ]);
        $resultat = NoteWorkflowService::envoyer(
            $request->user()->agent,
            $note,
            $request->boolean('tous'),
            $data['structures'] ?? [],
            $data['agents'] ?? [],
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->format($resultat['note']),
            'destinataires' => $resultat['destinataires'],
            'emails' => $resultat['emails'],
        ]);
    }

    public function supprimer(Request $request, NoteService $note)
    {
        NoteWorkflowService::supprimer($request->user()->agent, $note);

        return response()->json(['status' => 'success']);
    }

    /** Document Word : téléchargement (ou aperçu) réservé aux personnes concernées par la note. */
    public function fichier(Request $request, NoteService $note)
    {
        $user = $request->user();
        if (! NoteWorkflowService::peutConsulter($user, $note)) {
            abort(403, 'Vous n’avez pas accès à cette note de service.');
        }
        if (! Storage::disk('local')->exists($note->fichier_path)) {
            abort(404, 'Le document de cette note est introuvable.');
        }
        if ($user->agent) {
            NoteWorkflowService::marquerLue($user->agent, $note);
        }

        return Storage::disk('local')->download($note->fichier_path, $note->fichier_nom);
    }

    /** Destinataires possibles pour la secrétaire : structures (avec effectif) et agents. */
    public function destinatairesPossibles()
    {
        return response()->json([
            'status' => 'success',
            'structures' => Structure::withCount('agents')
                ->orderBy('nom')
                ->get(['id', 'code', 'nom', 'sigle', 'type'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'nom' => $s->nom,
                    'sigle' => $s->sigle ?: $s->code,
                    'type' => $s->type,
                    'effectif' => $s->agents_count,
                ]),
            'agents' => Agent::with('structure:id,nom,sigle,code')
                ->orderBy('nom')
                ->orderBy('prenom')
                ->get(['id', 'matricule', 'civilite', 'nom', 'prenom', 'structure_id'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'nom' => $a->fullName(),
                    'matricule' => $a->matricule,
                    'structure' => $a->structure?->sigle ?: $a->structure?->nom,
                ]),
        ]);
    }

    /** Agent du compte connecté (un compte sans agent ne peut pas agir sur une note). */
    private function agentOuRefus(Request $request): Agent
    {
        return $request->user()->agent ?? abort(403, 'Votre compte n’est rattaché à aucun agent.');
    }

    /** Représentation d'une note pour l'interface ; avec son historique pour la secrétaire et le
     * directeur. */
    private function format(NoteService $note, bool $avecHistorique = false): array
    {
        $donnees = [
            'id' => $note->id,
            'numero' => $note->numero,
            'objet' => $note->objet,
            'date_note' => $note->date_note?->format('Y-m-d'),
            'statut' => $note->statut,
            'fichier_nom' => $note->fichier_nom,
            'fichier_taille' => $note->fichier_taille,
            'apercu' => str_ends_with(strtolower($note->fichier_nom), '.docx'),
            'secretaire' => $note->secretaire?->fullName(),
            'directeur' => $note->directeur?->fullName(),
            'motif_retour' => $note->motif_retour,
            'transmise_le' => $note->transmise_le?->format('Y-m-d H:i:s'),
            'validee_le' => $note->validee_le?->format('Y-m-d H:i:s'),
            'envoyee_le' => $note->envoyee_le?->format('Y-m-d H:i:s'),
            'destinataires' => $note->statut === NoteService::ENVOYEE
                ? [
                    'resume' => NoteWorkflowService::resumeDestinataires($note),
                    'total' => $note->destinataires()->count(),
                    'lus' => $note->destinataires()->whereNotNull('lu_le')->count(),
                ]
                : null,
        ];
        if ($avecHistorique) {
            $donnees['historique'] = $note->historique->map(fn ($h) => [
                'action' => $h->action,
                'acteur' => $h->acteur_nom,
                'role' => $h->acteur_role,
                'date' => $h->created_at?->format('Y-m-d H:i:s'),
                'commentaire' => $h->commentaire,
            ])->values();
        }

        return $donnees;
    }
}
