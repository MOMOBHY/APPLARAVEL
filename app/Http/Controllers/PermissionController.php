<?php

namespace App\Http\Controllers;

use App\Models\DemandePermission;
use App\Services\PermissionWorkflowService;
use App\Services\PieceService;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    /** Tableau des demandes de permission (séparé des naissances), avec ses filtres (statut, dates, type,
     * structure) et sa recherche. */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = DemandePermission::with(['agent.structure', 'type'])->latest();

        if ($user->hasRole('ROLE_SOUS_DIRECTEUR')) {
            $query->where('statut', DemandePermission::EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR);
        } elseif ($user->hasRole('ROLE_DIRECTEUR')) {
            $query->where('statut', DemandePermission::EN_ATTENTE_VALIDATION_DIRECTEUR);
        } elseif ($user->hasRole('ROLE_DRH')) {
            $query->where('statut', DemandePermission::EN_ATTENTE_DRH);
        } elseif ($user->hasRole('ROLE_GESTIONNAIRE_RH')) {
            $query->where(function ($q) use ($user) {
                $q->where('statut', DemandePermission::EN_ATTENTE_RH)
                    ->orWhere('gestionnaire_id', $user->agent_id)
                    ->orWhereIn('statut', [DemandePermission::VALIDEE, DemandePermission::REJETEE]);
            });
        } elseif (! $user->hasRole('ROLE_ADMIN_DSI')) {
            $query->where('agent_id', $user->agent_id);
        }

        // Filtres du dashboard permission (sans mélanger avec les naissances).
        if ($request->filled('statut')) {
            $query->where('statut', $request->input('statut'));
        }
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }
        if ($request->filled('date_debut')) {
            $query->whereDate('date_debut', '>=', $request->input('date_debut'));
        }
        if ($request->filled('date_fin')) {
            $query->whereDate('date_fin', '<=', $request->input('date_fin'));
        }
        if ($request->filled('type_permission_id')) {
            $query->where('type_permission_id', $request->input('type_permission_id'));
        }
        if ($request->filled('structure_id')) {
            $query->whereHas('agent', fn ($q) => $q->where('structure_id', $request->input('structure_id')));
        }
        if ($request->filled('search')) {
            $s = '%'.trim($request->input('search')).'%';
            $query->where(function ($q) use ($s) {
                $q->where('code_dossier', 'like', $s)
                    ->orWhere('motif', 'like', $s)
                    ->orWhereHas('agent', fn ($qq) => $qq->where('matricule', 'like', $s)
                        ->orWhere('nom', 'like', $s)->orWhere('prenom', 'like', $s));
            });
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

    /** Dépôt d'une permission : « Brouillon » (sans transmission) ou « Soumettre » (EN_ATTENTE_RH,
     * gestionnaire RH notifié). Durée calculée sur les dates, bornes comprises. */
    public function store(Request $request)
    {
        $soumettre = filter_var($request->input('soumettre', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        $data = $request->validate([
            'type_permission_id' => [$soumettre ? 'required' : 'nullable', 'exists:types_permission,id'],
            'date_debut' => [$soumettre ? 'required' : 'nullable', 'date'],
            'date_fin' => [$soumettre ? 'required' : 'nullable', 'date', 'after_or_equal:date_debut'],
            'motif' => [$soumettre ? 'required' : 'nullable', 'string', 'min:3'],
            'nombre_jours' => ['nullable', 'integer', 'min:1', 'max:30'],
            'piece' => [
                'nullable',
                'file',
                'mimes:'.implode(',', PieceService::MIMES),
                'max:'.PieceService::MAX_KO,
            ],
            'soumettre' => ['nullable', 'boolean'],
        ]);

        if (! $soumettre) {
            $demande = DemandePermission::create([
                'code_dossier' => 'PERM-'.now()->year.'-'.strtoupper(\Illuminate\Support\Str::random(6)),
                'agent_id' => $request->user()->agent_id,
                'type_permission_id' => $data['type_permission_id'] ?? \App\Models\TypePermission::orderBy('id')->firstOrFail()->id,
                'date_debut' => $data['date_debut'] ?? now()->format('Y-m-d'),
                'date_fin' => $data['date_fin'] ?? $data['date_debut'] ?? now()->format('Y-m-d'),
                'nombre_jours' => $data['nombre_jours'] ?? 1,
                'motif' => $data['motif'] ?? '',
                'statut' => DemandePermission::BROUILLON,
            ]);
            PermissionWorkflowService::tracer(
                $demande,
                $request->user()->agent,
                'ROLE_AGENT',
                'BROUILLON',
                null,
                $demande->statut,
                'Brouillon enregistré, sans transmission.',
            );

            return response()->json(['status' => 'success', 'data' => $demande], 201);
        }

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

    /** Gestionnaire RH : Transmettre (conforme) | Retourner pour correction | Rejeter. */
    public function verifier(Request $request, DemandePermission $permission)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:transmettre,conforme,retourner,corriger,rejeter'],
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

    /** Sous-directeur / Directeur : Valider ou Rejeter (cas ≤ 2 jours uniquement). */
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
}
