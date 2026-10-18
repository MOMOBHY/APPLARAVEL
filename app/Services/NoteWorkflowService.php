<?php

namespace App\Services;

use App\Mail\NoteServiceEnvoyee;
use App\Models\Agent;
use App\Models\JournalAudit;
use App\Models\NoteDestinataire;
use App\Models\NoteHistorique;
use App\Models\NoteService;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Circuit des notes de service : la secrétaire dépose le document Word et le transmet à son directeur ;
 * le directeur le valide ou le retourne avec un motif ; une fois validée, la secrétaire choisit les
 * destinataires et envoie la note, par e-mail (document joint) et dans l'application. */
class NoteWorkflowService
{
    /** Taille maximale du document, en Ko. */
    public const MAX_KO = 5120;

    /** Formats acceptés : documents Word. */
    public const EXTENSIONS = ['doc', 'docx'];

    /** Types MIME admis pour un document Word (selon le poste, un .docx est parfois vu comme une archive). */
    public const MIMES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/zip',
        'application/x-zip-compressed',
        'application/octet-stream',
        'application/vnd.ms-office',
        'application/CDFV2',
    ];

    /** Comptes de direction : en l'absence de responsable désigné, le directeur de la secrétaire est le
     * compte de direction de sa structure (le Directeur de Cabinet pour le Cabinet). */
    public const ROLES_DIRECTION = [
        'ROLE_DIRECTEUR_CABINET',
        'ROLE_DIRECTEUR',
        'ROLE_DRH',
        'ROLE_SOUS_DIRECTEUR',
    ];

    /** Bilan des e-mails du dernier envoi (consulté par le contrôleur et les tests). */
    public static array $dernierBilanEmails = ['envoyes' => 0, 'ignores' => 0, 'echecs' => 0];

    // ---------------------------------------------------------------------------------------------
    // Directeur de la secrétaire
    // ---------------------------------------------------------------------------------------------

    /** Directeur de la secrétaire : le responsable de sa structure, sinon le compte de direction actif
     * rattaché à la même structure. */
    public static function directeurDe(Agent $secretaire): ?Agent
    {
        $structure = $secretaire->structure;
        if ($structure?->responsable_agent_id && $structure->responsable_agent_id !== $secretaire->id) {
            return $structure->responsable;
        }
        if (! $secretaire->structure_id) {
            return null;
        }

        return Agent::where('structure_id', $secretaire->structure_id)
            ->whereKeyNot($secretaire->id)
            ->whereHas(
                'user',
                fn ($u) => $u->where('actif', true)->whereHas(
                    'roles',
                    fn ($r) => $r->whereIn('code', self::ROLES_DIRECTION),
                ),
            )
            ->orderBy('id')
            ->first();
    }

    // ---------------------------------------------------------------------------------------------
    // Secrétaire : dépôt, nouvelle version, transmission, envoi
    // ---------------------------------------------------------------------------------------------

    /** Dépôt du document Word (note en brouillon). */
    public static function deposer(Agent $secretaire, string $objet, string $dateNote, UploadedFile $fichier): NoteService
    {
        $note = NoteService::create([
            'numero' => self::prochainNumero($secretaire),
            'objet' => $objet,
            'date_note' => $dateNote,
            'statut' => NoteService::BROUILLON,
            'secretaire_id' => $secretaire->id,
        ] + self::enregistrerFichier($fichier));
        self::tracer($note, $secretaire, 'DEPOT', 'Document déposé : '.$note->fichier_nom);

        return $note;
    }

    /** Nouvelle version du document (brouillon ou note retournée par le directeur). */
    public static function remplacerDocument(
        Agent $secretaire,
        NoteService $note,
        UploadedFile $fichier,
        ?string $objet = null,
        ?string $dateNote = null,
    ): NoteService {
        self::exigerAutrice($note, $secretaire);
        self::exigerStatut($note, [NoteService::BROUILLON, NoteService::A_CORRIGER], 'remplacer le document');
        $ancien = $note->fichier_path;
        $note->update(array_filter(['objet' => $objet, 'date_note' => $dateNote]) + self::enregistrerFichier($fichier));
        Storage::disk('local')->delete($ancien);
        self::tracer($note, $secretaire, 'NOUVELLE_VERSION', 'Nouveau document : '.$note->fichier_nom);

        return $note->refresh();
    }

    /** Transmission au directeur de la secrétaire, qui est prévenu. */
    public static function transmettre(Agent $secretaire, NoteService $note): NoteService
    {
        self::exigerAutrice($note, $secretaire);
        self::exigerStatut($note, [NoteService::BROUILLON, NoteService::A_CORRIGER], 'transmettre');
        $directeur = self::directeurDe($secretaire);
        if (! $directeur) {
            abort(422, 'Aucun directeur n’est rattaché à votre structure : la note ne peut pas être transmise.');
        }
        $note->update([
            'statut' => NoteService::EN_ATTENTE_VALIDATION,
            'directeur_id' => $directeur->id,
            'transmise_le' => now(),
            'motif_retour' => null,
        ]);
        self::notifier(
            $directeur->id,
            'Note de service à valider',
            "{$secretaire->fullName()} vous transmet la note « {$note->objet} » pour validation.",
            'NOTE_A_VALIDER',
            $note->numero,
        );
        self::tracer($note, $secretaire, 'TRANSMISSION', 'Transmise à '.$directeur->fullName());

        return $note->refresh();
    }

    /** Suppression d'un brouillon (jamais transmis). */
    public static function supprimer(Agent $secretaire, NoteService $note): void
    {
        self::exigerAutrice($note, $secretaire);
        self::exigerStatut($note, [NoteService::BROUILLON], 'supprimer');
        Storage::disk('local')->delete($note->fichier_path);
        JournalAudit::noter(JournalAudit::DOSSIER, 'SUPPRESSION_NOTE', $secretaire->user, $note->objet, $note->numero);
        $note->delete();
    }

    /** Envoi de la note validée aux destinataires choisis : tous les agents, des structures et/ou des
     * agents désignés. Chaque destinataire la reçoit dans l'application et par e-mail (document joint). */
    public static function envoyer(
        Agent $secretaire,
        NoteService $note,
        bool $tous,
        array $structureIds = [],
        array $agentIds = [],
    ): array {
        self::exigerAutrice($note, $secretaire);
        self::exigerStatut($note, [NoteService::VALIDEE], 'envoyer');

        $choisis = collect($agentIds)->map(fn ($id) => (int) $id)->unique();
        $parStructure = $tous
            ? Agent::pluck('id')
            : Agent::whereIn('structure_id', $structureIds)->pluck('id');
        $destinataires = $parStructure->merge($choisis)->unique()->values();
        if ($destinataires->isEmpty()) {
            abort(422, 'Choisissez au moins un destinataire.');
        }

        DB::transaction(function () use ($note, $tous, $structureIds, $choisis, $destinataires, $secretaire) {
            $note->structures()->sync($tous ? [] : $structureIds);
            $maintenant = now();
            NoteDestinataire::insert(
                $destinataires
                    ->map(fn ($id) => [
                        'note_id' => $note->id,
                        'agent_id' => $id,
                        'choisi' => $choisis->contains($id),
                        'created_at' => $maintenant,
                        'updated_at' => $maintenant,
                    ])
                    ->all(),
            );
            $note->update([
                'statut' => NoteService::ENVOYEE,
                'envoi_a_tous' => $tous,
                'envoyee_le' => $maintenant,
            ]);
            foreach ($destinataires as $agentId) {
                self::notifier(
                    $agentId,
                    'Nouvelle note de service',
                    $note->objet,
                    'NOTE_SERVICE',
                    $note->numero,
                );
            }
            self::tracer(
                $note,
                $secretaire,
                'ENVOI',
                self::resumeDestinataires($note->refresh()).' — '.$destinataires->count().' destinataire(s)',
            );
        });

        $bilan = self::envoyerEmails($note, $destinataires);

        return ['note' => $note->refresh(), 'destinataires' => $destinataires->count(), 'emails' => $bilan];
    }

    // ---------------------------------------------------------------------------------------------
    // Directeur : validation ou retour
    // ---------------------------------------------------------------------------------------------

    /** Validation par le directeur à qui la note a été transmise ; la secrétaire est prévenue. */
    public static function valider(Agent $directeur, NoteService $note): NoteService
    {
        self::exigerDirecteurDeLaNote($note, $directeur);
        self::exigerStatut($note, [NoteService::EN_ATTENTE_VALIDATION], 'valider');
        $note->update(['statut' => NoteService::VALIDEE, 'validee_le' => now()]);
        self::notifier(
            $note->secretaire_id,
            'Note de service validée',
            "{$directeur->fullName()} a validé la note « {$note->objet} ». Vous pouvez l’envoyer.",
            'NOTE_VALIDEE',
            $note->numero,
        );
        self::tracer($note, $directeur, 'VALIDATION');

        return $note->refresh();
    }

    /** Retour à la secrétaire avec un motif : elle charge une version corrigée puis retransmet. */
    public static function retourner(Agent $directeur, NoteService $note, string $motif): NoteService
    {
        self::exigerDirecteurDeLaNote($note, $directeur);
        self::exigerStatut($note, [NoteService::EN_ATTENTE_VALIDATION], 'retourner');
        $note->update(['statut' => NoteService::A_CORRIGER, 'motif_retour' => $motif]);
        self::notifier(
            $note->secretaire_id,
            'Note de service à corriger',
            "{$directeur->fullName()} vous retourne la note « {$note->objet} » : {$motif}",
            'NOTE_RETOURNEE',
            $note->numero,
        );
        self::tracer($note, $directeur, 'RETOUR', $motif);

        return $note->refresh();
    }

    // ---------------------------------------------------------------------------------------------
    // Consultation
    // ---------------------------------------------------------------------------------------------

    /** Ouvrir ou télécharger le document : la secrétaire autrice, le directeur à qui la note a été
     * transmise, un destinataire (note envoyée) ou l'administrateur. */
    public static function peutConsulter(User $user, NoteService $note): bool
    {
        $agentId = $user->agent_id;
        if ($user->hasRole('ROLE_ADMIN_DSI')) {
            return true;
        }
        if ($agentId && ($note->secretaire_id === $agentId || $note->directeur_id === $agentId)) {
            return true;
        }

        return $note->statut === NoteService::ENVOYEE
            && $agentId
            && $note->destinataires()->where('agent_id', $agentId)->exists();
    }

    /** Marque la note comme lue par ce destinataire (première ouverture). */
    public static function marquerLue(Agent $agent, NoteService $note): void
    {
        NoteDestinataire::where('note_id', $note->id)
            ->where('agent_id', $agent->id)
            ->whereNull('lu_le')
            ->update(['lu_le' => now()]);
    }

    /** Destinataires en clair : « Tous les agents du ministère », sinon structures et agents désignés. */
    public static function resumeDestinataires(NoteService $note): string
    {
        if ($note->envoi_a_tous) {
            return 'Tous les agents du ministère';
        }
        $parties = $note->structures()->orderBy('nom')->pluck('nom');
        $choisis = $note->destinataires()->where('choisi', true)->count();
        if ($choisis) {
            $parties->push($choisis.' agent(s) désigné(s)');
        }

        return $parties->implode(', ');
    }

    // ---------------------------------------------------------------------------------------------
    // Outils
    // ---------------------------------------------------------------------------------------------

    /** Enregistre le document sur le disque privé et renvoie ses colonnes. */
    protected static function enregistrerFichier(UploadedFile $fichier): array
    {
        return [
            'fichier_path' => $fichier->store('notes', 'local'),
            'fichier_nom' => $fichier->getClientOriginalName(),
            'fichier_taille' => (int) $fichier->getSize(),
        ];
    }

    /** Numéro de la note : N° 0001/MFP/<structure de la secrétaire>/<année>. */
    protected static function prochainNumero(Agent $secretaire): string
    {
        $code = $secretaire->structure?->code ?: 'MFP';
        $annee = now()->year;
        $rang = NoteService::whereYear('created_at', $annee)->count() + 1;
        do {
            $numero = 'N° '.str_pad((string) $rang++, 4, '0', STR_PAD_LEFT)."/MFP/{$code}/{$annee}";
        } while (NoteService::where('numero', $numero)->exists());

        return $numero;
    }

    /** E-mails aux destinataires (adresse du compte, sinon celle de l'agent), document en pièce jointe. */
    protected static function envoyerEmails(NoteService $note, Collection $agentIds): array
    {
        $bilan = ['envoyes' => 0, 'ignores' => 0, 'echecs' => 0];
        $dejaFaits = [];
        $agents = Agent::with('user')->whereIn('id', $agentIds)->get();
        foreach ($agents as $agent) {
            $adresse = strtolower(trim((string) ($agent->user?->email ?: $agent->email)));
            if ($adresse === '' || ! filter_var($adresse, FILTER_VALIDATE_EMAIL)) {
                $bilan['ignores']++;

                continue;
            }
            if (isset($dejaFaits[$adresse])) {
                continue;
            }
            $dejaFaits[$adresse] = true;
            try {
                Mail::to($adresse)->send(new NoteServiceEnvoyee($note, $agent->fullName()));
                $bilan['envoyes']++;
            } catch (Throwable $e) {
                report($e);
                $bilan['echecs']++;
            }
        }
        self::$dernierBilanEmails = $bilan;

        return $bilan;
    }

    protected static function exigerAutrice(NoteService $note, Agent $secretaire): void
    {
        if ($note->secretaire_id !== $secretaire->id) {
            abort(403, 'Cette note a été déposée par une autre secrétaire.');
        }
    }

    protected static function exigerDirecteurDeLaNote(NoteService $note, Agent $directeur): void
    {
        if (! $note->directeur_id || $note->directeur_id !== $directeur->id) {
            abort(403, 'Seul le directeur à qui la note a été transmise peut la valider ou la retourner.');
        }
    }

    protected static function exigerStatut(NoteService $note, array $statuts, string $action): void
    {
        if (! in_array($note->statut, $statuts, true)) {
            abort(422, "Impossible de {$action} cette note dans son état actuel.");
        }
    }

    /** Inscrit une étape dans l'historique de la note et dans le journal d'audit. */
    protected static function tracer(NoteService $note, ?Agent $acteur, string $action, ?string $commentaire = null): void
    {
        $role = $acteur?->user?->roles()->value('libelle');
        NoteHistorique::create([
            'note_id' => $note->id,
            'acteur_agent_id' => $acteur?->id,
            'acteur_nom' => $acteur?->fullName(),
            'acteur_role' => $role ? mb_substr($role, 0, 40) : null,
            'action' => $action,
            'commentaire' => $commentaire,
        ]);
        JournalAudit::noter(JournalAudit::DOSSIER, 'NOTE_'.$action, $acteur?->user, $commentaire ?: $note->objet, $note->numero);
    }

    /** Crée une notification pour un agent. */
    protected static function notifier(int $agentId, string $titre, string $message, string $type, string $reference): void
    {
        Notification::create([
            'agent_id' => $agentId,
            'titre' => $titre,
            'message' => $message,
            'type' => $type,
            'reference_dossier' => $reference,
        ]);
    }
}
