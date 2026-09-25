<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Role;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'matricule' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with(['roles', 'agent.structure', 'agent.fonction'])
            ->where('matricule', strtoupper(trim($data['matricule'])))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            JournalAudit::noter(JournalAudit::CONNEXION, 'CONNEXION_REFUSEE', $user, $user ? 'Mot de passe incorrect' : 'Matricule inconnu', null, false, $data['matricule']);
            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Matricule ou mot de passe incorrect.'], 401);
            }

            return back()->withErrors(['matricule' => 'Matricule ou mot de passe incorrect.'])->onlyInput('matricule');
        }

        $user->update(['derniere_connexion' => now()]);
        JournalAudit::noter(JournalAudit::CONNEXION, 'CONNEXION', $user, 'Connexion');

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'success',
                'token' => $user->createToken('gfp')->plainTextToken,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'matricule' => $user->matricule,
                    'roles' => $user->roles->pluck('code'),
                    'agent_id' => $user->agent_id,
                    'structure' => $user->agent?->structure?->nom,
                ],
            ]);
        }

        // Formulaire Blade : session web (le jeton Sanctum n'est jamais renvoyé par le navigateur).
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'civilite' => ['required', 'string', 'max:10'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:150'],
            'matricule' => ['required', 'string', 'max:30', 'unique:agents,matricule'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'structure_id' => ['nullable', 'exists:structures,id'],
            'fonction_id' => ['nullable', 'exists:fonctions,id'],
        ]);

        $agent = Agent::create([
            'matricule' => strtoupper(trim($data['matricule'])),
            'civilite' => $data['civilite'],
            'nom' => strtoupper(trim($data['nom'])),
            'prenom' => trim($data['prenom']),
            'structure_id' => $data['structure_id'] ?? null,
            'fonction_id' => $data['fonction_id'] ?? null,
        ]);

        $user = User::create([
            'name' => $agent->fullName(),
            'email' => strtolower($agent->matricule).'@fonctionpublique.gouv.ci',
            'matricule' => $agent->matricule,
            'password' => $data['password'],
            'agent_id' => $agent->id,
            'structure_id' => $agent->structure_id,
        ]);
        $user->roles()->attach(Role::where('code', 'ROLE_AGENT')->firstOrFail());

        return response()->json(['status' => 'success', 'message' => 'Compte créé.', 'matricule' => $user->matricule], 201);
    }

    public function logout(Request $request)
    {
        JournalAudit::noter(JournalAudit::CONNEXION, 'DECONNEXION', $request->user(), 'Déconnexion');

        // Une session web porte un TransientToken, qui n'est pas supprimable.
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'success']);
        }

        return redirect()->route('login');
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['roles', 'agent.structure']);

        return response()->json(['status' => 'success', 'user' => $user]);
    }
}
