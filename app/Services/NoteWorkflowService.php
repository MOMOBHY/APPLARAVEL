<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\JournalAudit;
use App\Models\NoteHistorique;
use App\Models\NoteService;
use App\Models\Notification;
use App\Models\Structure;
use App\Models\User;
use App\Notifications\NoteServiceTransmise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Workflow des notes de service, indépendant des permissions (pas de
 * Gestionnaire RH dans ce circuit) :
 * AUTORITÉ ÉMETTRICE (DRH, Directeur de Cabinet, Directeur, Sous-Directeur)
 * → SA SECRÉTAIRE (saisie) → TRANSMISSION par email et notification aux
 * destinataires (Directeurs, Sous-Directeurs, Chefs de service, agents).
 * La validation par l'autorité reste possible mais n'est pas requise.
 */
class NoteWorkflowService
{
    /** Autorités habilitées à émettre une note de service. */
    public const ROLES_EMETTEURS = ['ROLE_DRH', 'ROLE_DIRECTEUR_CABINET', 'ROLE_DIRECTEUR', 'ROLE_SOUS_DIRECTEUR'];

    /** Rôles du circuit interne, qui consultent toutes les notes quel que soit leur état. */
    public const ROLES_INTERNES = ['ROLE_ADMIN_DSI', 'ROLE_DRH', 'ROLE_DIRECTEUR', 'ROLE_SOUS_DIRECTEUR', 'ROLE_SECRETAIRE'];

    /**
     * Notes consultables par l'utilisateur : tout pour le circuit interne ;
     * sinon les notes diffusées/archivées de sa structure et celles qu'il a émises.
     *
     * @return Builder<NoteService>
     */
    public static function visiblesPour(User $user): Builder
    {
        $query = NoteService::query();
        if ($user->hasRole(...self::ROLES_INTERNES)) {
            return $query;
        }

        return $query->where(function (Builder $visibles) use ($user) {
            $visibles->where(function (Builder $diffusees) use ($user) {
                $diffusees->whereIn('statut', [NoteService::DIFFUSEE, NoteService::ARCHIVEE])
                    ->whereHas('structures', fn (Builder $s) => $s->where('structures.id', $user->agent?->structure_id));
            });
            if ($user->agent_id) {
                $visibles->orWhere('signataire_id', $user->agent_id);
            }
        });
    }

    /** Étape 1 — rédaction par une autorité habilitée. */
    public static function rediger(Agent $autorite, array $data, ?string $fichierPath): NoteService
    {
        $note = NoteService::create([
            'numero_reference' => self::prochainNumero(),
            'objet' => $data['objet'],
            'contenu' => $data['contenu'] ?? null,
            'fichier_path' => $fichierPath,
            'signataire_id' => $autorite->id,
            'statut' => NoteService::BROUILLON,
            'date_emission' => $data['date_emission'] ?? now()->toDateString(),
        ]);
        $note->structures()->sync(self::structuresCibles($data['structure_ids'] ?? null));

        self::tracer($note, $autorite, 'AUTORITE', 'REDACTION', null, $note->statut,
            "Note rédigée par {$autorite->fullName()}.");

        return $note->refresh();
    }

    /** Transmettre au secrétariat pour saisie. */
    public static function transmettreSecretariat(NoteService $note, Agent $autorite): NoteService
    {
        if ($note->statut !== NoteService::BROUILLON) {
            abort(422, 'Seul un brouillon peut être transmis au secrétariat.');
        }
        if ($note->signataire_id !== $autorite->id) {
            abort(403, "Seule l'autorité émettrice transmet sa note.");
        }

        $ancien = $note->statut;
        $note->update(['statut' => NoteService::EN_ATTENTE_SAISIE]);
        self::tracer($note, $autorite, 'AUTORITE', 'TRANSMISSION_SECRETARIAT', $ancien, $note->statut,
            'Note transmise au secrétariat.');

        foreach (self::secretairesDe($autorite) as $secretaire) {
            self::notifier($secretaire->id, 'Note à saisir',
                "La note {$note->numero_reference} attend saisie et mise en forme.", 'ATTENTE_SAISIE', $note->numero_reference);
        }

        return $note->refresh();
    }

    /** Contrôle à faire avant tout dépôt de fichier : la note attend la saisie du secrétariat. */
    public static function exigerSaisissable(NoteService $note): void
    {
        if ($note->statut !== NoteService::EN_ATTENTE_SAISIE) {
            abort(422, 'Saisie impossible à ce stade.');
        }
    }

    /** Étape 2 — saisie / mise en forme / enregistrement par le secrétaire. */
    public static function saisir(NoteService $note, Agent $secretaire, array $data): NoteService
    {
        self::exigerSaisissable($note);

        $ancien = $note->statut;
        $note->update([
            'contenu' => $data['contenu'] ?? $note->contenu,
            'numero_reference' => $data['numero_reference'] ?? $note->numero_reference,
            'fichier_path' => $data['fichier_path'] ?? $note->fichier_path,
            'secretaire_id' => $secretaire->id,
            'statut' => NoteService::EN_ATTENTE_VALIDATION,
        ]);
        if (! empty($data['structure_ids'])) {
            $note->structures()->sync(self::structuresCibles($data['structure_ids']));
        }
        self::tracer($note, $secretaire, 'ROLE_SECRETAIRE', 'SAISIE_MISE_EN_FORME', $ancien, $note->statut,
            'Note saisie, mise en forme et enregistrée.');

        self::notifier($note->signataire_id, 'Note saisie par le secrétariat',
            "La note {$note->numero_reference} est saisie et prête à être transmise aux destinataires.",
            'INFO', $note->numero_reference);

        return $note->refresh();
    }

    /** Étape 3 — validation par l'autorité habilitée (diffusion bloquée sinon). */
    public static function valider(NoteService $note, Agent $autorite): NoteService
    {
        if ($note->statut !== NoteService::EN_ATTENTE_VALIDATION) {
            abort(422, 'Validation impossible à ce stade.');
        }
        if ($note->signataire_id !== $autorite->id) {
            abort(403, "Seule l'autorité émettrice valide sa note.");
        }

        $ancien = $note->statut;
        $note->update(['statut' => NoteService::VALIDEE, 'valide_le' => now(), 'valideur_id' => $autorite->id]);
        self::tracer($note, $autorite, 'AUTORITE', 'VALIDATION', $ancien, $note->statut, 'Note validée, diffusable.');

        return $note->refresh();
    }

    /** Refus motivé de validation par l'autorité émettrice. */
    public static function refuser(NoteService $note, Agent $autorite, string $motif): NoteService
    {
        if ($note->statut !== NoteService::EN_ATTENTE_VALIDATION) {
            abort(422, 'Refus impossible à ce stade.');
        }
        if ($note->signataire_id !== $autorite->id) {
            abort(403, "Seule l'autorité émettrice refuse sa note.");
        }
        if (blank($motif)) {
            abort(422, 'Motif de refus obligatoire.');
        }

        $ancien = $note->statut;
        $note->update(['statut' => NoteService::REJETEE]);
        self::tracer($note, $autorite, 'AUTORITE', 'REFUS_VALIDATION', $ancien, $note->statut, $motif);

        foreach (self::secretaires() as $secretaire) {
            self::notifier($secretaire->id, 'Note refusée à la validation',
                "La note {$note->numero_reference} a été refusée : {$motif}", 'REFUS_VALIDATION', $note->numero_reference);
        }

        return $note->refresh();
    }

    /** Étape 3 — transmission aux destinataires par la secrétaire, une fois la note saisie. */
    public static function diffuser(NoteService $note, Agent $secretaire): NoteService
    {
        if (! in_array($note->statut, [NoteService::EN_ATTENTE_VALIDATION, NoteService::VALIDEE], true)) {
            abort(422, 'Seule une note saisie (et non refusée) peut être transmise.');
        }

        $ancien = $note->statut;
        $note->update([
            'statut' => NoteService::DIFFUSEE,
            'secretaire_id' => $note->secretaire_id ?? $secretaire->id,
            'date_diffusion' => now(),
        ]);
        self::tracer($note, $secretaire, 'ROLE_SECRETAIRE', 'DIFFUSION', $ancien, $note->statut,
            'Note diffusée aux structures destinataires.');

        $agents = Agent::with('user')->whereIn('structure_id', $note->structures()->pluck('structures.id'))->get();
        foreach ($agents as $agent) {
            self::notifier($agent->id, 'Nouvelle note de service',
                "{$note->numero_reference} : {$note->objet}", 'NOTE_SERVICE', $note->numero_reference);
        }

        // Transmission par email ; une panne de messagerie ne bloque pas la diffusion.
        try {
            NotificationFacade::send($agents->pluck('user')->filter(), new NoteServiceTransmise($note->loadMissing('signataire')));
        } catch (Throwable $e) {
            report($e);
        }

        return $note->refresh();
    }

    public static function archiver(NoteService $note, Agent $acteur): NoteService
    {
        if (! in_array($note->statut, [NoteService::DIFFUSEE, NoteService::VALIDEE], true)) {
            abort(422, 'Seule une note validée ou diffusée peut être archivée.');
        }
        $ancien = $note->statut;
        $note->update(['statut' => NoteService::ARCHIVEE]);
        self::tracer($note, $acteur, null, 'ARCHIVAGE', $ancien, $note->statut, 'Note archivée, toujours consultable.');

        return $note->refresh();
    }

    /** Étape 5 — destinataires exacts (émetteur + admin DSI). */
    public static function destinataires(NoteService $note): array
    {
        $structures = $note->structures()->get();
        $agents = Agent::with('structure')->whereIn('structure_id', $structures->pluck('id'))
            ->get(['id', 'matricule', 'nom', 'prenom', 'structure_id']);

        return ['structures' => $structures, 'agents' => $agents];
    }

    /**
     * Numéro suivant libre : le secrétariat peut avoir attribué manuellement
     * un numéro qui entrerait sinon en collision avec le compteur.
     */
    protected static function prochainNumero(): string
    {
        $rang = NoteService::count() + 1;
        do {
            $numero = 'NOTE N° '.str_pad((string) $rang++, 4, '0', STR_PAD_LEFT).'/MFP/CAB/'.now()->year;
        } while (NoteService::where('numero_reference', $numero)->exists());

        return $numero;
    }

    /** « Sa secrétaire » : secrétaires de la structure de l'autorité, sinon tout le secrétariat. */
    protected static function secretairesDe(Agent $autorite): Collection
    {
        $siennes = self::secretaires()->where('structure_id', $autorite->structure_id);

        return $siennes->isNotEmpty() ? $siennes : self::secretaires();
    }

    protected static function secretaires(): Collection
    {
        return Agent::whereHas('user.roles', fn ($q) => $q->where('code', 'ROLE_SECRETAIRE'))->get();
    }

    protected static function structuresCibles(mixed $structures): array
    {
        if (is_array($structures) && $structures !== []) {
            return Structure::whereIn('id', $structures)->pluck('id')->all();
        }
        if (is_string($structures) && trim($structures) !== '') {
            $trouvees = Structure::where('nom', 'like', '%'.trim($structures).'%')->pluck('id')->all();

            return $trouvees === [] ? Structure::pluck('id')->all() : $trouvees;
        }

        return Structure::pluck('id')->all();
    }

    public static function tracer(NoteService $note, ?Agent $acteur, ?string $role, string $action, ?string $ancien, ?string $nouveau, ?string $commentaire = null): void
    {
        NoteHistorique::create([
            'note_id' => $note->id,
            'acteur_agent_id' => $acteur?->id,
            'acteur_nom' => $acteur?->fullName(),
            'acteur_role' => $role,
            'action' => $action,
            'ancien_statut' => $ancien,
            'nouveau_statut' => $nouveau,
            'commentaire' => $commentaire,
        ]);
        JournalAudit::noter(JournalAudit::DOSSIER, $action, $acteur?->user, trim(($role ? "[{$role}] " : '').($ancien || $nouveau ? "{$ancien} → {$nouveau}" : '').($commentaire ? " — {$commentaire}" : '')), $note->numero_reference);
    }

    protected static function notifier(int $agentId, string $titre, string $message, string $type, ?string $ref): void
    {
        Notification::create([
            'agent_id' => $agentId, 'titre' => $titre, 'message' => $message, 'type' => $type, 'reference_dossier' => $ref,
        ]);
    }
}
