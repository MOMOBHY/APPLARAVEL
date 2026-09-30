<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\JournalAudit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** Connexion par matricule et mot de passe. Refuse les comptes suspendus et journalise chaque
     * tentative. Formulaire Blade : session web ; appel JSON : jeton d'accès. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'matricule' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with(['roles', 'agent.structure', 'agent.fonction'])
            ->where('matricule', strtoupper(trim($data['matricule'])))
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            JournalAudit::noter(
                JournalAudit::CONNEXION,
                'CONNEXION_REFUSEE',
                $user,
                $user ? 'Mot de passe incorrect' : 'Matricule inconnu',
                null,
                false,
                $data['matricule'],
            );
            if ($request->expectsJson()) {
                return response()->json(
                    ['status' => 'error', 'message' => 'Matricule ou mot de passe incorrect.'],
                    401,
                );
            }

            return back()
                ->withErrors(['matricule' => 'Matricule ou mot de passe incorrect.'])
                ->onlyInput('matricule');
        }

        if (! $user->actif) {
            JournalAudit::noter(
                JournalAudit::CONNEXION,
                'CONNEXION_REFUSEE',
                $user,
                'Compte suspendu',
                null,
                false,
            );
            $message = 'Ce compte est suspendu. Contactez l’administrateur.';

            return $request->expectsJson()
                ? response()->json(['status' => 'error', 'message' => $message], 403)
                : back()
                    ->withErrors(['matricule' => $message])
                    ->onlyInput('matricule');
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

    /** Inscription d'un agent avec son compte et son rôle. */
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

        return response()->json(
            ['status' => 'success', 'message' => 'Compte créé.', 'matricule' => $user->matricule],
            201,
        );
    }

    /** Déconnexion : supprime le jeton d'accès (ou ferme la session web) et journalise la sortie. */
    public function logout(Request $request)
    {
        JournalAudit::noter(
            JournalAudit::CONNEXION,
            'DECONNEXION',
            $request->user(),
            'Déconnexion',
        );

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

    /** Renvoie l'utilisateur connecté avec ses rôles et sa structure. */
    public function me(Request $request)
    {
        $user = $request->user()->load(['roles', 'agent.structure']);

        return response()->json(['status' => 'success', 'user' => $user]);
    }

    /** Mon compte : l'utilisateur modifie son nom, son prénom, son email et son mot de passe (mot de
     * passe actuel exigé) ; la session en cours est gardée. */
    public function updateProfil(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'nom' => ['nullable', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:150'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                'unique:users,email,'.$user->id,
                function ($attribut, $valeur, $echec) {
                    $domaine = strtolower(trim($valeur));
                    if (! str_ends_with($domaine, '@fonctionpublique.gouv.ci') && ! str_ends_with($domaine, '@gmail.com')) {
                        $echec("L'adresse email doit finir par @fonctionpublique.gouv.ci ou @gmail.com.");
                    }
                },
            ],
            'mot_de_passe_actuel' => ['nullable', 'string', 'required_with:nouveau_mot_de_passe'],
            'nouveau_mot_de_passe' => ['nullable', 'string', 'min:6', 'confirmed'],
        ]);

        if (
            blank($data['nom'] ?? null) && blank($data['prenom'] ?? null) &&
            blank($data['email'] ?? null) && blank($data['nouveau_mot_de_passe'] ?? null)
        ) {
            return response()->json(['status' => 'error', 'message' => 'Rien à enregistrer.'], 422);
        }

        if (! empty($data['nouveau_mot_de_passe']) && ! Hash::check($data['mot_de_passe_actuel'] ?? '', $user->password)) {
            return response()->json(
                ['status' => 'error', 'message' => 'Mot de passe actuel incorrect.'],
                403,
            );
        }

        $agent = $user->agent;
        if ((! empty($data['nom']) || ! empty($data['prenom'])) && ! $agent) {
            return response()->json(
                ['status' => 'error', 'message' => 'Aucun dossier agent lié à ce compte.'],
                422,
            );
        }
        if ($agent) {
            if (! empty($data['nom'])) {
                $agent->nom = strtoupper(trim($data['nom']));
            }
            if (! empty($data['prenom'])) {
                $agent->prenom = trim($data['prenom']);
            }
            $agent->save();
            $user->name = $agent->fullName();
        }
        if (! empty($data['email'])) {
            $user->email = strtolower(trim($data['email']));
        }
        if (! empty($data['nouveau_mot_de_passe'])) {
            $user->password = $data['nouveau_mot_de_passe'];
        }
        $user->save();
        JournalAudit::noter(
            JournalAudit::COMPTE,
            'PROFIL_MODIFIE',
            $user,
            'Profil mis à jour par l’utilisateur.',
        );

        return response()->json([
            'status' => 'success',
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }
}
