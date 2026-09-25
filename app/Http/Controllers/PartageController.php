<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\JournalAudit;
use App\Models\Notification;
use App\Models\Partage;
use App\Services\PieceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PartageController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $partages = Partage::visiblesPour($user)->with(['auteur.fonction', 'structures:id,nom,sigle', 'piece'])
            ->latest('id')->limit(100)->get()
            ->map(fn (Partage $p) => [
                'id' => $p->id,
                'titre' => $p->titre,
                'message' => $p->message,
                'toutes_structures' => $p->toutes_structures,
                'structures' => $p->structures->map(fn ($s) => $s->sigle ?: $s->nom)->values(),
                'auteur' => $p->auteur?->fullName(),
                'date' => $p->created_at?->format('Y-m-d H:i'),
                'piece_id' => $p->piece_id,
                'piece' => $p->piece?->nom_fichier,
                'supprimable' => $user->hasRole('ROLE_ADMIN_DSI') || $p->auteur_agent_id === $user->agent_id,
            ]);

        return response()->json(['status' => 'success', 'peut_partager' => Partage::peutPartager($user), 'partages' => $partages]);
    }

    public function store(Request $request)
    {
        abort_unless(Partage::peutPartager($request->user()), 403, 'Votre rôle ne permet pas de partager.');

        $data = $request->validate([
            'titre' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:5000'],
            'toutes_structures' => ['nullable', 'boolean'],
            'structure_ids' => ['nullable', 'array'],
            'structure_ids.*' => ['exists:structures,id'],
            'fichier' => ['nullable', 'file', 'mimes:'.implode(',', PieceService::MIMES_PARTAGE), 'max:'.PieceService::MAX_KO],
        ]);
        $toutes = (bool) ($data['toutes_structures'] ?? false);
        if (! $toutes && empty($data['structure_ids'])) {
            return response()->json(['status' => 'error', 'message' => 'Choisissez au moins une structure, ou « Toutes les structures ».'], 422);
        }
        if (empty($data['message']) && ! $request->hasFile('fichier')) {
            return response()->json(['status' => 'error', 'message' => 'Ajoutez un message ou un document.'], 422);
        }

        $auteur = $request->user()->agent;
        $partage = DB::transaction(function () use ($data, $toutes, $request, $auteur) {
            $partage = Partage::create([
                'auteur_agent_id' => $auteur?->id,
                'titre' => $data['titre'],
                'message' => $data['message'] ?? null,
                'toutes_structures' => $toutes,
            ]);
            if (! $toutes) {
                $partage->structures()->sync($data['structure_ids']);
            }
            if ($request->hasFile('fichier')) {
                $piece = PieceService::deposer($request->file('fichier'), 'partage', $partage->id, 'PARTAGE-'.$partage->id, $auteur);
                $partage->update(['piece_id' => $piece->id]);
            }

            return $partage;
        });

        $destinataires = Agent::query()
            ->when(! $toutes, fn ($q) => $q->whereIn('structure_id', $data['structure_ids']))
            ->when($auteur, fn ($q) => $q->where('id', '!=', $auteur->id))
            ->pluck('id');
        foreach ($destinataires as $agentId) {
            Notification::create([
                'agent_id' => $agentId,
                'titre' => 'Nouveau partage : '.$partage->titre,
                'message' => ($auteur?->fullName() ?? 'Le ministère').' a partagé une information avec votre structure.',
                'type' => 'PARTAGE',
                'reference_dossier' => 'PARTAGE-'.$partage->id,
            ]);
        }
        JournalAudit::noter(JournalAudit::PARTAGE, 'PARTAGE_PUBLIE', $request->user(), "« {$partage->titre} » partagé avec ".($toutes ? 'toutes les structures' : count($data['structure_ids']).' structure(s)'), 'PARTAGE-'.$partage->id);

        return response()->json(['status' => 'success', 'id' => $partage->id, 'destinataires' => $destinataires->count()], 201);
    }

    public function destroy(Request $request, Partage $partage)
    {
        abort_unless($request->user()->hasRole('ROLE_ADMIN_DSI') || $partage->auteur_agent_id === $request->user()->agent_id, 403, 'Suppression non autorisée.');

        if ($partage->piece) {
            Storage::disk('local')->delete($partage->piece->chemin_stockage);
            $partage->piece->delete();
        }
        JournalAudit::noter(JournalAudit::PARTAGE, 'PARTAGE_SUPPRIME', $request->user(), "« {$partage->titre} » supprimé", 'PARTAGE-'.$partage->id);
        $partage->delete();

        return response()->json(['status' => 'success']);
    }
}
