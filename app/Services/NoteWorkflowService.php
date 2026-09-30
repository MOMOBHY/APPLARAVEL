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

/** Notes de service : la secrétaire rédige et envoie aux directeurs ; un directeur valide (la secrétaire
 * diffuse) ou refuse avec motif (elle reprend et renvoie). */
class NoteWorkflowService
{
    /** Rôle qui rédige une note de service : la secrétaire (vers les directeurs) et le DRH (vers les
     * agents de sa direction, avec diffusion directe). */
    public const ROLES_EMETTEURS = ['ROLE_SECRETAIRE', 'ROLE_DRH'];

    /** Bilan du dernier envoi d'emails de diffusion (envoyés, échecs, comptes sans adresse), repris dans
     * la réponse de l'API pour informer la secrétaire. */
    public static ?array $dernierBilanEmails = null;

    /** Rôles du circuit interne, qui consultent toutes les notes quel que soit leur état. */
    public const ROLES_INTERNES = [
        'ROLE_ADMIN_DSI',
        'ROLE_DRH',
        'ROLE_DIRECTEUR',
        'ROLE_SOUS_DIRECTEUR',
        'ROLE_SECRETAIRE',
        // Consultation seule : le gestionnaire RH voit les notes de service
        // sans pouvoir les émettre, les saisir, les valider ni les diffuser.
        'ROLE_GESTIONNAIRE_RH',
    ];

    /** Notes consultables par l'utilisateur : tout pour le circuit interne ; sinon les notes
     * diffusées/archivées de sa structure et celles qu'il a émises. */
    public static function visiblesPour(User $user): Builder
    {
        $query = NoteService::query();
        if ($user->hasRole(...self::ROLES_INTERNES)) {
            return $query;
        }

        return $query->where(function (Builder $visibles) use ($user) {
            $visibles->where(function (Builder $diffusees) use ($user) {
                $diffusees
                    ->whereIn('statut', [NoteService::DIFFUSEE, NoteService::ARCHIVEE])
                    ->whereHas(
                        'structures',
                        fn (Builder $s) => $s->where('structures.id', $user->agent?->structure_id),
                    );
            });
            if ($user->agent_id) {
                $visibles->orWhere('signataire_id', $user->agent_id);
            }
        });
    }

    /** Étape 1 — rédaction (brouillon) par la secrétaire ou le DRH. */
    public static function rediger(Agent $auteur, array $data, ?string $fichierPath): NoteService
    {
        self::exigerRedacteur($auteur);
        $note = NoteService::create([
            'numero_reference' => self::prochainNumero(),
            'objet' => $data['objet'],
            'contenu' => $data['contenu'] ?? null,
            'fichier_path' => $fichierPath,
            'signataire_id' => $auteur->id,
            'secretaire_id' => $auteur->user?->hasRole('ROLE_SECRETAIRE') ? $auteur->id : null,
            'statut' => NoteService::BROUILLON,
            'date_emission' => $data['date_emission'] ?? now()->toDateString(),
        ]);
        $note->structures()->sync(self::structuresCibles($data['structure_ids'] ?? null));

        self::tracer(
            $note,
            $auteur,
            $auteur->user?->hasRole('ROLE_DRH') ? 'ROLE_DRH' : 'ROLE_SECRETAIRE',
            'REDACTION',
            null,
            $note->statut,
            "Note rédigée par {$auteur->fullName()}.",
        );

        return $note->refresh();
    }

    /** Étape 2 — la secrétaire envoie sa note à tous les directeurs : chacun la reçoit dans son coin « À
     * valider ». Vaut aussi reprise après un refus. */
    public static function envoyerAuxDirecteurs(NoteService $note, Agent $secretaire): NoteService
    {
        if (! in_array($note->statut, [NoteService::BROUILLON, NoteService::A_REPRENDRE], true)) {
            abort(422, 'Seule une note rédigée ou reprise peut être envoyée aux directeurs.');
        }
        self::exigerSecretaire($secretaire);

        $ancien = $note->statut;
        $note->update(['statut' => NoteService::EN_ATTENTE_VALIDATION, 'secretaire_id' => $secretaire->id]);
        self::tracer(
            $note,
            $secretaire,
            'ROLE_SECRETAIRE',
            'ENVOI_DIRECTEURS',
            $ancien,
            $note->statut,
            'Note envoyée aux directeurs pour validation.',
        );

        foreach (self::directeurs() as $directeur) {
            self::notifier(
                $directeur->id,
                'Note à valider',
                "La note {$note->numero_reference} attend votre validation.",
                'ATTENTE_VALIDATION',
                $note->numero_reference,
            );
        }

        return $note->refresh();
    }

    /** La secrétaire reprend une note refusée : elle la corrige avant de la renvoyer. */
    public static function reprendre(NoteService $note, Agent $secretaire, array $data): NoteService
    {
        if (! in_array($note->statut, [NoteService::BROUILLON, NoteService::A_REPRENDRE], true)) {
            abort(422, 'Reprise impossible à ce stade.');
        }
        self::exigerSecretaire($secretaire);

        $ancien = $note->statut;
        $note->update([
            'objet' => $data['objet'] ?? $note->objet,
            'contenu' => $data['contenu'] ?? $note->contenu,
            'secretaire_id' => $secretaire->id,
        ]);
        if (! empty($data['structure_ids'])) {
            $note->structures()->sync(self::structuresCibles($data['structure_ids']));
        }
        self::tracer(
            $note,
            $secretaire,
            'ROLE_SECRETAIRE',
            'REPRISE',
            $ancien,
            $note->statut,
            'Note reprise par le secrétariat.',
        );

        return $note->refresh();
    }

    /** Contrôle à faire avant toute saisie : la note attend le secrétariat — reprise d'une note refusée
     * par un directeur. */
    public static function exigerSaisissable(NoteService $note): void
    {
        if (! in_array($note->statut, [NoteService::EN_ATTENTE_SAISIE, NoteService::A_REPRENDRE], true)) {
            abort(422, 'Saisie impossible à ce stade.');
        }
    }

    /** Étape 2 (reprise) — la secrétaire modifie une note refusée et la renvoie aux directeurs. */
    public static function saisir(NoteService $note, Agent $secretaire, array $data): NoteService
    {
        self::exigerSaisissable($note);
        self::exigerSecretaire($secretaire);

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
        self::tracer(
            $note,
            $secretaire,
            'ROLE_SECRETAIRE',
            'SAISIE_MISE_EN_FORME',
            $ancien,
            $note->statut,
            'Note reprise et renvoyée aux directeurs.',
        );

        foreach (self::directeurs() as $directeur) {
            self::notifier(
                $directeur->id,
                'Note à valider',
                "La note {$note->numero_reference} attend votre validation.",
                'ATTENTE_VALIDATION',
                $note->numero_reference,
            );
        }

        return $note->refresh();
    }

    /** Étape 3 — un directeur valide la note : c'est le feu vert qui autorise la secrétaire à la
     * diffuser. La note étant envoyée à tous les directeurs, la première validation l'emporte. */
    public static function valider(NoteService $note, Agent $directeur): NoteService
    {
        if ($note->statut !== NoteService::EN_ATTENTE_VALIDATION) {
            abort(422, 'Validation impossible à ce stade.');
        }
        self::exigerDirecteur($directeur, 'valide la note');

        $ancien = $note->statut;
        $note->update([
            'statut' => NoteService::VALIDEE,
            'valide_le' => now(),
            'valideur_id' => $directeur->id,
        ]);
        self::tracer(
            $note,
            $directeur,
            'ROLE_DIRECTEUR',
            'VALIDATION',
            $ancien,
            $note->statut,
            "Note validée par {$directeur->fullName()}, diffusable.",
        );

        foreach (self::secretaires() as $secretaire) {
            self::notifier(
                $secretaire->id,
                'Note validée à diffuser',
                "La note {$note->numero_reference} est validée : diffusez-la aux services.",
                'VALIDEE',
                $note->numero_reference,
            );
        }

        return $note->refresh();
    }

    /** Un directeur refuse la note : elle revient à la secrétaire, qui la reprend avant de la renvoyer
     * aux directeurs. Le refus n'est donc pas un arrêt du circuit, c'est un retour pour correction. */
    public static function refuser(NoteService $note, Agent $directeur, string $motif): NoteService
    {
        if ($note->statut !== NoteService::EN_ATTENTE_VALIDATION) {
            abort(422, 'Refus impossible à ce stade.');
        }
        self::exigerDirecteur($directeur, 'refuse la note');
        if (blank($motif)) {
            abort(422, 'Motif de refus obligatoire.');
        }

        $ancien = $note->statut;
        $note->update(['statut' => NoteService::A_REPRENDRE]);
        self::tracer(
            $note,
            $directeur,
            'ROLE_DIRECTEUR',
            'REFUS_VALIDATION',
            $ancien,
            $note->statut,
            $motif,
        );

        foreach (self::secretaires() as $secretaire) {
            self::notifier(
                $secretaire->id,
                'Note à reprendre',
                "La note {$note->numero_reference} a été refusée et vous revient pour correction : {$motif}",
                'REFUS_VALIDATION',
                $note->numero_reference,
            );
        }

        return $note->refresh();
    }

    /** Étape 4 — la secrétaire diffuse. Un directeur a dû valider au préalable : sans son feu vert, la
     * note reste au secretariat. */
    public static function diffuser(NoteService $note, Agent $secretaire): NoteService
    {
        if ($note->statut !== NoteService::VALIDEE) {
            abort(422, "La note doit être validée par le directeur avant d'être diffusée.");
        }

        $ancien = $note->statut;
        $note->update([
            'statut' => NoteService::DIFFUSEE,
            'secretaire_id' => $note->secretaire_id ?? $secretaire->id,
            'date_diffusion' => now(),
        ]);
        self::tracer(
            $note,
            $secretaire,
            'ROLE_SECRETAIRE',
            'DIFFUSION',
            $ancien,
            $note->statut,
            'Note diffusée aux structures destinataires et envoyée par email à tous les agents du ministère.',
        );
        self::avertirDestinataires($note, emailATousLesAgents: true);

        return $note->refresh();
    }

    /** Le DRH diffuse directement sa note aux agents de sa direction, sans circuit de validation : son
     * autorité vaut feu vert. */
    public static function diffuserDirectement(NoteService $note, Agent $drh): NoteService
    {
        if ($note->statut !== NoteService::BROUILLON) {
            abort(422, 'Seul un brouillon peut être diffusé directement.');
        }
        if (! $drh->user?->hasRole('ROLE_DRH')) {
            abort(403, 'Diffusion directe réservée à la DRH.');
        }
        if ($note->signataire_id !== $drh->id) {
            abort(403, 'Seul le DRH auteur diffuse sa note.');
        }

        $ancien = $note->statut;
        $note->update([
            'statut' => NoteService::DIFFUSEE,
            'valide_le' => now(),
            'valideur_id' => $drh->id,
            'date_diffusion' => now(),
        ]);
        self::tracer(
            $note,
            $drh,
            'ROLE_DRH',
            'DIFFUSION_DIRECTE',
            $ancien,
            $note->statut,
            'Note de la DRH diffusée directement, sans validation.',
        );
        self::avertirDestinataires($note);

        return $note->refresh();
    }

    /** Notifie les agents des structures destinataires et leur transmet la note par email ; une panne de
     * messagerie ne bloque pas la diffusion. */
    protected static function avertirDestinataires(NoteService $note, bool $emailATousLesAgents = false): void
    {
        $agents = Agent::with('user')
            ->whereIn('structure_id', $note->structures()->pluck('structures.id'))
            ->get();
        foreach ($agents as $agent) {
            self::notifier(
                $agent->id,
                'Nouvelle note de service',
                "{$note->numero_reference} : {$note->objet}",
                'NOTE_SERVICE',
                $note->numero_reference,
            );
        }

        // Diffusion par la secrétaire : l'email part à tous les agents du ministère
        // (comptes actifs). Diffusion directe du DRH : aux seules structures choisies.
        $destinataires = $emailATousLesAgents
            ? User::where('actif', true)->get()
            : $agents->pluck('user')->filter();

        self::$dernierBilanEmails = self::envoyerEmails($note->loadMissing('signataire'), $destinataires);
    }

    /** Envoie l'email de la note à chaque destinataire, un par un : une adresse en échec n'empêche pas
     * les suivantes et le bilan compte les échecs. Un compte sans adresse est compté comme ignoré. */
    protected static function envoyerEmails(NoteService $note, Collection $destinataires): array
    {
        $bilan = ['envoyes' => 0, 'echecs' => 0, 'ignores' => 0];
        foreach ($destinataires->unique('id') as $user) {
            $email = strtolower(trim((string) $user->email));
            if ($email === '') {
                $bilan['ignores']++;

                continue;
            }
            try {
                NotificationFacade::send($user, new NoteServiceTransmise($note));
                $bilan['envoyes']++;
            } catch (Throwable $e) {
                report($e);
                $bilan['echecs']++;
            }
        }

        return $bilan;
    }

    /** Archive une note validée ou diffusée. La note reste consultable. */
    public static function archiver(NoteService $note, Agent $acteur): NoteService
    {
        if (! in_array($note->statut, [NoteService::DIFFUSEE, NoteService::VALIDEE], true)) {
            abort(422, 'Seule une note validée ou diffusée peut être archivée.');
        }
        $ancien = $note->statut;
        $note->update(['statut' => NoteService::ARCHIVEE]);
        self::tracer(
            $note,
            $acteur,
            null,
            'ARCHIVAGE',
            $ancien,
            $note->statut,
            'Note archivée, toujours consultable.',
        );

        return $note->refresh();
    }

    /** Garde-fous du circuit : la rédaction appartient à la secrétaire et au DRH ; l'envoi aux directeurs
     * et la reprise à la secrétaire seule ; la validation et le refus à un directeur. */
    protected static function exigerRedacteur(Agent $acteur): void
    {
        if (! $acteur->user?->hasRole(...self::ROLES_EMETTEURS)) {
            abort(403, 'Rédaction réservée au secrétariat et à la DRH.');
        }
    }

    protected static function exigerSecretaire(Agent $acteur): void
    {
        if (! $acteur->user?->hasRole('ROLE_SECRETAIRE')) {
            abort(403, 'Action réservée au secrétariat.');
        }
    }

    protected static function exigerDirecteur(Agent $acteur, string $action): void
    {
        if (! $acteur->user?->hasRole('ROLE_DIRECTEUR')) {
            abort(403, "Seul un directeur {$action} une note de service.");
        }
    }

    /** Étape 5 — destinataires exacts (émetteur + admin DSI). */
    public static function destinataires(NoteService $note): array
    {
        $structures = $note->structures()->get();
        $agents = Agent::with('structure')
            ->whereIn('structure_id', $structures->pluck('id'))
            ->get(['id', 'matricule', 'nom', 'prenom', 'structure_id']);

        return ['structures' => $structures, 'agents' => $agents];
    }

    /** Numéro suivant libre : le secrétariat peut avoir attribué manuellement un numéro qui entrerait
     * sinon en collision avec le compteur. */
    protected static function prochainNumero(): string
    {
        $rang = NoteService::count() + 1;
        do {
            $numero =
                'NOTE N° '.
                str_pad((string) $rang++, 4, '0', STR_PAD_LEFT).
                '/MFP/CAB/'.
                now()->year;
        } while (NoteService::where('numero_reference', $numero)->exists());

        return $numero;
    }

    /** « Sa secrétaire » : secrétaires de la structure de l'autorité, sinon tout le secrétariat. */
    protected static function secretairesDe(Agent $autorite): Collection
    {
        $siennes = self::secretaires()->where('structure_id', $autorite->structure_id);

        return $siennes->isNotEmpty() ? $siennes : self::secretaires();
    }

    /** Retrouve tous les directeurs (agents portant le rôle ROLE_DIRECTEUR). */
    protected static function directeurs(): Collection
    {
        return Agent::whereHas('user.roles', fn ($q) => $q->where('code', 'ROLE_DIRECTEUR'))->get();
    }

    /** Retrouve toutes les secrétaires (agents portant le rôle ROLE_SECRETAIRE). */
    protected static function secretaires(): Collection
    {
        return Agent::whereHas('user.roles', fn ($q) => $q->where('code', 'ROLE_SECRETAIRE'))->get();
    }

    /** Structures destinataires d'une note : liste d'identifiants ou nom (recherche partielle) ; sans
     * indication ou nom introuvable, toutes les structures. */
    protected static function structuresCibles(mixed $structures): array
    {
        if (is_array($structures) && $structures !== []) {
            return Structure::whereIn('id', $structures)->pluck('id')->all();
        }
        if (is_string($structures) && trim($structures) !== '') {
            $trouvees = Structure::where('nom', 'like', '%'.trim($structures).'%')
                ->pluck('id')
                ->all();

            return $trouvees === [] ? Structure::pluck('id')->all() : $trouvees;
        }

        return Structure::pluck('id')->all();
    }

    /** Inscrit une étape dans l'historique de la note et dans le journal d'audit. */
    public static function tracer(
        NoteService $note,
        ?Agent $acteur,
        ?string $role,
        string $action,
        ?string $ancien,
        ?string $nouveau,
        ?string $commentaire = null,
    ): void {
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
        JournalAudit::noter(
            JournalAudit::DOSSIER,
            $action,
            $acteur?->user,
            trim(
                ($role ? "[{$role}] " : '').
                    ($ancien || $nouveau ? "{$ancien} → {$nouveau}" : '').
                    ($commentaire ? " — {$commentaire}" : ''),
            ),
            $note->numero_reference,
        );
    }

    /** Crée une notification pour un agent. */
    protected static function notifier(
        int $agentId,
        string $titre,
        string $message,
        string $type,
        ?string $ref,
    ): void {
        Notification::create([
            'agent_id' => $agentId,
            'titre' => $titre,
            'message' => $message,
            'type' => $type,
            'reference_dossier' => $ref,
        ]);
    }
}
