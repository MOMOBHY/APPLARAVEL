<?php

namespace App\Http\Controllers;

use App\Models\DemandePermission;
use App\Services\PermissionWorkflowService;
use App\Services\PieceService;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    /**
     * Liste des demandes de permission. Chaque rôle voit son périmètre : le Sous-Directeur les
     * demandes en attente de son visa, le Directeur celles en attente du sien, le DRH celles en
     * attente de sa décision, le gestionnaire RH celles à vérifier, les siennes et les dossiers
     * clôturés, l'administrateur tout, et l'agent uniquement ses propres demandes.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = DemandePermission::with(['agent.structure', 'type'])->latest();

        if ($user->hasRole('ROLE_SOUS_DIRECTEUR')) {
            $query->where('statut', DemandePermission::EN_ATTENTE_VISA_SOUS_DIRECTEUR);
        } elseif ($user->hasRole('ROLE_DIRECTEUR')) {
            $query->where('statut', DemandePermission::EN_ATTENTE_VISA_DIRECTEUR);
        } elseif ($user->hasRole('ROLE_DRH')) {
            $query->where('statut', DemandePermission::EN_ATTENTE_DRH);
        } elseif ($user->hasRole('ROLE_GESTIONNAIRE_RH')) {
            $query->where(function ($q) use ($user) {
                $q->where('statut', DemandePermission::EN_ATTENTE_GESTIONNAIRE_RH)
                    ->orWhere('gestionnaire_id', $user->agent_id)
                    ->orWhereIn('statut', [DemandePermission::VALIDEE, DemandePermission::REJETEE]);
            });
        } elseif (! $user->hasRole('ROLE_ADMIN_DSI')) {
            $query->where('agent_id', $user->agent_id);
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'data' => $query->paginate(20)]);
        }

        return view('permissions.index', ['demandes' => $query->paginate(20)]);
    }

    /** Détail d'une demande avec son historique. Réservé au demandeur et aux rôles de suivi. */
    public function show(Request $request, DemandePermission $permission)
    {
        $user = $request->user();
        abort_unless(
            $permission->agent_id === $user->agent_id ||
                $user->hasRole(...PermissionWorkflowService::ROLES_SUIVI),
            403,
            'Accès non autorisé à cette demande.',
        );

        $permission->load(['agent.structure', 'type', 'historique']);

        return response()->json(['status' => 'success', 'data' => $permission]);
    }

    /**
     * Dépose une demande de permission. La durée doit être de 1 à 30 jours ; le justificatif est
     * enregistré sur le disque privé.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type_permission_id' => ['required', 'exists:types_permission,id'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'motif' => ['required', 'string', 'min:5'],
            'nombre_jours' => ['nullable', 'integer', 'min:1', 'max:30'],
            'piece' => [
                'nullable',
                'file',
                'mimes:'.implode(',', PieceService::MIMES),
                'max:'.PieceService::MAX_KO,
            ],
        ]);

        $demande = PermissionWorkflowService::soumettre($request->user()->agent, [
            'type_permission_id' => $data['type_permission_id'],
            'date_debut' => $data['date_debut'],
            'date_fin' => $data['date_fin'],
            'motif' => $data['motif'],
            'nombre_jours' => $data['nombre_jours'] ?? null,
            'piece_path' => null,
        ]);

        if ($request->hasFile('piece')) {
            $piece = PieceService::deposer(
                $request->file('piece'),
                'permission',
                $demande->id,
                $demande->code_dossier,
                $request->user()->agent,
            );
            $demande->update(['piece_path' => $piece->chemin_stockage]);
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'data' => $demande], 201);
        }

        return redirect()
            ->route('permissions.index')
            ->with('success', "Demande {$demande->code_dossier} soumise au gestionnaire RH.");
    }

    /** Agent corrige une demande retournée puis la resoumet. */
    public function corriger(Request $request, DemandePermission $permission)
    {
        $data = $request->validate([
            'type_permission_id' => ['nullable', 'exists:types_permission,id'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'motif' => ['nullable', 'string', 'min:5'],
            'nombre_jours' => ['nullable', 'integer', 'min:1', 'max:30'],
            'piece' => [
                'nullable',
                'file',
                'mimes:'.implode(',', PieceService::MIMES),
                'max:'.PieceService::MAX_KO,
            ],
        ]);

        PermissionWorkflowService::exigerCorrigeable($permission, $request->user()->agent);

        if ($request->hasFile('piece')) {
            $piece = PieceService::deposer(
                $request->file('piece'),
                'permission',
                $permission->id,
                $permission->code_dossier,
                $request->user()->agent,
            );
            $data['piece_path'] = $piece->chemin_stockage;
        }

        $demande = PermissionWorkflowService::corrigerEtResoumettre(
            $permission,
            $request->user()->agent,
            $data,
        );

        return response()->json(['status' => 'success', 'data' => $demande]);
    }

    /** Gestionnaire RH : conforme (visa SOUS_DIRECTEUR ou DIRECTEUR au choix si ≤ 2 j) | rejeter | corriger (retour correction). */
    public function verifier(Request $request, DemandePermission $permission)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:conforme,rejeter,corriger'],
            'motif' => ['nullable', 'string', 'min:3'],
            'visa' => ['nullable', 'in:SOUS_DIRECTEUR,DIRECTEUR'],
        ]);

        $demande = PermissionWorkflowService::verifierRh(
            $permission,
            $request->user()->agent,
            $data['decision'],
            $data['motif'] ?? null,
            $data['visa'] ?? null,
        );

        return response()->json(['status' => 'success', 'data' => $demande]);
    }

    /** Sous-directeur / Directeur : visa favorable ou refus motivé. */
    public function viser(Request $request, DemandePermission $permission)
    {
        $data = $request->validate([
            'favorable' => ['required', 'boolean'],
            'motif' => ['nullable', 'string', 'min:3'],
        ]);

        $user = $request->user();
        $role = $user->hasRole('ROLE_SOUS_DIRECTEUR') ? 'SOUS_DIRECTEUR' : 'DIRECTEUR';

        $demande = PermissionWorkflowService::viser(
            $permission,
            $user->agent,
            $role,
            (bool) $data['favorable'],
            $data['motif'] ?? null,
        );

        return response()->json(['status' => 'success', 'data' => $demande]);
    }

    /** DRH : validation ou rejet motivé. */
    public function trancher(Request $request, DemandePermission $permission)
    {
        $data = $request->validate([
            'valide' => ['required', 'boolean'],
            'motif' => ['nullable', 'string', 'min:3'],
        ]);

        $demande = PermissionWorkflowService::trancherDrh(
            $permission,
            $request->user()->agent,
            (bool) $data['valide'],
            $data['motif'] ?? null,
        );

        return response()->json(['status' => 'success', 'data' => $demande]);
    }

    /** Gestionnaire RH : notifie l'agent de la décision finale. */
    public function notifier(Request $request, DemandePermission $permission)
    {
        $demande = PermissionWorkflowService::notifierAgent($permission, $request->user()->agent);

        return response()->json(['status' => 'success', 'data' => $demande]);
    }
}
