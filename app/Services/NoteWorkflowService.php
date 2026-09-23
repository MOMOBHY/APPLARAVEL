<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\NoteHistorique;
use App\Models\NoteService;
use App\Models\Notification;
use App\Models\Structure;
use Illuminate\Support\Collection;

/**
 * Workflow des notes de service, indépendant des permissions (pas de
 * Gestionnaire RH dans ce circuit) :
 * AUTORITÉ (rédaction) → SECRÉTAIRE (saisie/mise en forme/enregistrement)
 * → VALIDATION (autorité) → DIFFUSION → DESTINATAIRES (consultation).
 */
class NoteWorkflowService
{
    /** Étape 1 — rédaction par une autorité habilitée. */
    public static function rediger(Agent $autorite, array $data, ?string $fichierPath): NoteService
    {
        $note = NoteService::create([
            'numero_reference' => 'NOTE N° '.str_pad((string) (NoteService::count() + 1), 4, '0', STR_PAD_LEFT).'/MFP/CAB/'.now()->year,
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

        foreach (self::secretaires() as $secretaire) {
            self::notifier($secretaire->id, 'Note à saisir',
                "La note {$note->numero_reference} attend saisie et mise en forme.", 'ATTENTE_SAISIE', $note->numero_reference);
        }

        return $note->refresh();
    }

    /** Étape 2 — saisie / mise en forme / enregistrement par le secrétaire. */
    public static function saisir(NoteService $note, Agent $secretaire, array $data): NoteService
    {
        if ($note->statut !== NoteService::EN_ATTENTE_SAISIE) {
            abort(422, 'Saisie impossible à ce stade.');
        }

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

        self::notifier($note->signataire_id, 'Note prête à valider',
            "La note {$note->numero_reference} est saisie, votre validation est requise avant diffusion.",
            'ATTENTE_VALIDATION', $note->numero_reference);

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

    /** Étape 4 — diffusion (note validée uniquement). */
    public static function diffuser(NoteService $note, Agent $secretaire): NoteService
    {
        if ($note->statut !== NoteService::VALIDEE) {
            abort(422, 'Seule une note validée peut être diffusée.');
        }

        $ancien = $note->statut;
        $note->update([
            'statut' => NoteService::DIFFUSEE,
            'secretaire_id' => $note->secretaire_id ?? $secretaire->id,
            'date_diffusion' => now(),
        ]);
        self::tracer($note, $secretaire, 'ROLE_SECRETAIRE', 'DIFFUSION', $ancien, $note->statut,
            'Note diffusée aux structures destinataires.');

        $agents = Agent::whereIn('structure_id', $note->structures()->pluck('structures.id'))->get();
        foreach ($agents as $agent) {
            self::notifier($agent->id, 'Nouvelle note de service',
                "{$note->numero_reference} : {$note->objet}", 'NOTE_SERVICE', $note->numero_reference);
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
    }

    protected static function notifier(int $agentId, string $titre, string $message, string $type, ?string $ref): void
    {
        Notification::create([
            'agent_id' => $agentId, 'titre' => $titre, 'message' => $message, 'type' => $type, 'reference_dossier' => $ref,
        ]);
    }
}
