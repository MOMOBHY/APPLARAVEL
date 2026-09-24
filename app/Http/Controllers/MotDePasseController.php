<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\DemandeReinitialisation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mot de passe oublié : l'utilisateur demande (et reçoit un code de suivi),
 * l'administrateur autorise ou refuse, puis l'utilisateur choisit son nouveau
 * mot de passe avec son matricule et son code.
 */
class MotDePasseController extends Controller
{
    public function demander(Request $request)
    {
        $data = $request->validate(['matricule' => ['required', 'string', 'max:30']]);

        $user = User::whereRaw('UPPER(matricule) = ?', [strtoupper(trim($data['matricule']))])->first();
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Matricule inconnu.'], 422);
        }

        // Une seule demande active par compte : les précédentes sont annulées.
        DemandeReinitialisation::where('user_id', $user->id)
            ->whereIn('statut', [DemandeReinitialisation::EN_ATTENTE, DemandeReinitialisation::AUTORISEE])
            ->update(['statut' => DemandeReinitialisation::ANNULEE]);

        $code = strtoupper(Str::random(8));
        DemandeReinitialisation::create(['user_id' => $user->id, 'code_hash' => Hash::make($code)]);

        $admins = Agent::whereHas('user.roles', fn ($q) => $q->where('code', 'ROLE_ADMIN_DSI'))->pluck('id');
        foreach ($admins as $adminAgentId) {
            Notification::create([
                'agent_id' => $adminAgentId,
                'titre' => 'Réinitialisation de mot de passe à autoriser',
                'message' => "{$user->name} ({$user->matricule}) a oublié son mot de passe.",
                'type' => 'REINITIALISATION',
                'reference_dossier' => $user->matricule,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'code' => $code,
            'message' => "Demande transmise à l'administrateur. Conservez votre code de suivi : il vous sera demandé pour choisir votre nouveau mot de passe.",
        ], 201);
    }

    public function reinitialiser(Request $request)
    {
        $data = $request->validate([
            'matricule' => ['required', 'string'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = User::whereRaw('UPPER(matricule) = ?', [strtoupper(trim($data['matricule']))])->first();
        $demande = $user
            ? DemandeReinitialisation::where('user_id', $user->id)->latest('id')->first()
            : null;

        if (! $demande || ! Hash::check(strtoupper(trim($data['code'])), $demande->code_hash)) {
            return response()->json(['status' => 'error', 'message' => 'Matricule ou code de suivi incorrect.'], 422);
        }

        $message = match ($demande->statut) {
            DemandeReinitialisation::EN_ATTENTE => "Votre demande est en attente de l'autorisation de l'administrateur.",
            DemandeReinitialisation::REFUSEE => "Votre demande a été refusée par l'administrateur.",
            DemandeReinitialisation::AUTORISEE => null,
            default => 'Ce code a déjà été utilisé ou annulé : faites une nouvelle demande.',
        };
        if ($message !== null) {
            return response()->json(['status' => 'error', 'etat' => $demande->statut, 'message' => $message], 422);
        }

        $user->password = $data['password'];
        $user->save();
        $user->tokens()->delete();
        $demande->update(['statut' => DemandeReinitialisation::UTILISEE, 'utilise_le' => now()]);

        return response()->json(['status' => 'success', 'message' => 'Mot de passe réinitialisé. Vous pouvez vous connecter.']);
    }

    /** Administrateur : demandes en attente d'autorisation. */
    public function index()
    {
        $demandes = DemandeReinitialisation::with('user.roles')
            ->where('statut', DemandeReinitialisation::EN_ATTENTE)->latest('id')->get()
            ->map(fn (DemandeReinitialisation $d) => [
                'id' => $d->id,
                'matricule' => $d->user->matricule,
                'nom' => $d->user->name,
                'roles' => $d->user->roles->pluck('libelle')->join(', '),
                'date' => $d->created_at?->format('Y-m-d H:i:s'),
            ]);

        return response()->json(['status' => 'success', 'demandes' => $demandes]);
    }

    public function autoriser(Request $request, DemandeReinitialisation $demande)
    {
        return $this->traiter($request, $demande, DemandeReinitialisation::AUTORISEE);
    }

    public function refuser(Request $request, DemandeReinitialisation $demande)
    {
        return $this->traiter($request, $demande, DemandeReinitialisation::REFUSEE);
    }

    private function traiter(Request $request, DemandeReinitialisation $demande, string $statut)
    {
        abort_unless($demande->statut === DemandeReinitialisation::EN_ATTENTE, 422, 'Demande déjà traitée.');
        $demande->update(['statut' => $statut, 'traite_par_id' => $request->user()->id, 'traite_le' => now()]);

        if ($agentId = $demande->user->agent_id) {
            Notification::create([
                'agent_id' => $agentId,
                'titre' => $statut === DemandeReinitialisation::AUTORISEE ? 'Réinitialisation autorisée' : 'Réinitialisation refusée',
                'message' => $statut === DemandeReinitialisation::AUTORISEE
                    ? "L'administrateur a autorisé la réinitialisation de votre mot de passe."
                    : "L'administrateur a refusé la réinitialisation de votre mot de passe.",
                'type' => 'REINITIALISATION',
                'reference_dossier' => $demande->user->matricule,
            ]);
        }

        return response()->json(['status' => 'success']);
    }
}
