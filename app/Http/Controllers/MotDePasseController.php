<?php

namespace App\Http\Controllers;

use App\Models\DemandeReinitialisation;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Mot de passe oublié : un code et un lien de réinitialisation sont envoyés à l'email du compte, puis
 * l'utilisateur choisit son mot de passe. Sans administrateur. */
class MotDePasseController extends Controller
{
    /** Durée de validité du code envoyé par email. */
    public const VALIDITE_MINUTES = 60;

    /** Masque une adresse pour l'affichage (« marie.dupont@… » devient « m***@… ») : l'utilisateur sait
     * où regarder sans exposer l'adresse complète. */
    private static function emailMasque(?string $email): string
    {
        $email = strtolower(trim((string) $email));
        $parties = explode('@', $email);
        if (count($parties) !== 2 || $parties[0] === '') {
            return 'votre adresse email';
        }

        return substr($parties[0], 0, 1).'***@'.$parties[1];
    }

    /** Étape 1 (publique) : le matricule saisi, le code et le lien de réinitialisation partent par email
     * ; les demandes précédentes sont annulées. */
    public function demander(Request $request)
    {
        $data = $request->validate(['matricule' => ['required', 'string', 'max:30']]);

        $user = User::whereRaw('UPPER(matricule) = ?', [
            strtoupper(trim($data['matricule'])),
        ])->first();
        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Matricule inconnu.'], 422);
        }
        if (blank($user->email)) {
            return response()->json(
                [
                    'status' => 'error',
                    'message' => "Aucune adresse email n'est enregistrée pour ce compte : contactez l'administrateur.",
                ],
                422,
            );
        }

        // Une seule demande active par compte : les précédentes sont annulées.
        DemandeReinitialisation::where('user_id', $user->id)
            ->whereIn('statut', [
                DemandeReinitialisation::EN_ATTENTE,
                DemandeReinitialisation::AUTORISEE,
            ])
            ->update(['statut' => DemandeReinitialisation::ANNULEE]);

        $code = strtoupper(Str::random(8));
        $demande = DemandeReinitialisation::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'statut' => DemandeReinitialisation::AUTORISEE,
        ]);

        JournalAudit::noter(
            JournalAudit::MOT_DE_PASSE,
            'REINITIALISATION_DEMANDEE',
            $user,
            'Mot de passe oublié : code envoyé par email',
        );

        $lien = rtrim(config('app.url'), '/').
            '/gfp/mot-de-passe-oublie.html?matricule='.
            urlencode($user->matricule).
            '&code='.
            urlencode($code);
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->send(
                new \App\Mail\CodeReinitialisation($user->name, $code, $lien),
            );
        } catch (\Throwable $e) {
            report($e);
            $demande->update(['statut' => DemandeReinitialisation::ANNULEE]);

            return response()->json(
                [
                    'status' => 'error',
                    'message' => "L'envoi de l'email a échoué : contactez l'administrateur.",
                ],
                503,
            );
        }

        return response()->json(
            [
                'status' => 'success',
                'email_masque' => self::emailMasque($user->email),
                'message' => "Un code de réinitialisation vient d'être envoyé à votre adresse email.",
            ],
            201,
        );
    }

    /** Étape 2 (publique) : l'utilisateur choisit un nouveau mot de passe avec son matricule et le code
     * reçu par email. Le code sert une seule fois. */
    public function reinitialiser(Request $request)
    {
        $data = $request->validate([
            'matricule' => ['required', 'string'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = User::whereRaw('UPPER(matricule) = ?', [
            strtoupper(trim($data['matricule'])),
        ])->first();
        $demande = $user
            ? DemandeReinitialisation::where('user_id', $user->id)->latest('id')->first()
            : null;

        if (! $demande || ! Hash::check(strtoupper(trim($data['code'])), $demande->code_hash)) {
            return response()->json(
                ['status' => 'error', 'message' => 'Matricule ou code de suivi incorrect.'],
                422,
            );
        }

        // Un code trop ancien ne sert plus : un email intercepté plus tard reste inutile.
        if (
            $demande->statut === DemandeReinitialisation::AUTORISEE &&
            $demande->created_at?->lt(now()->subMinutes(self::VALIDITE_MINUTES))
        ) {
            $demande->update(['statut' => DemandeReinitialisation::ANNULEE]);

            return response()->json(
                [
                    'status' => 'error',
                    'etat' => DemandeReinitialisation::ANNULEE,
                    'message' => 'Ce code a expiré : faites une nouvelle demande.',
                ],
                422,
            );
        }

        $message = match ($demande->statut) {
            DemandeReinitialisation::EN_ATTENTE => "Votre demande est en attente de l'autorisation de l'administrateur.",
            DemandeReinitialisation::REFUSEE => "Votre demande a été refusée par l'administrateur.",
            DemandeReinitialisation::AUTORISEE => null,
            default => 'Ce code a déjà été utilisé ou annulé : faites une nouvelle demande.',
        };
        if ($message !== null) {
            return response()->json(
                ['status' => 'error', 'etat' => $demande->statut, 'message' => $message],
                422,
            );
        }

        $user->password = $data['password'];
        $user->save();
        $user->tokens()->delete();
        $demande->update(['statut' => DemandeReinitialisation::UTILISEE, 'utilise_le' => now()]);
        JournalAudit::noter(
            JournalAudit::MOT_DE_PASSE,
            'MOT_DE_PASSE_REINITIALISE',
            $user,
            'Nouveau mot de passe choisi avec le code reçu par email',
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Mot de passe réinitialisé. Vous pouvez vous connecter.',
        ]);
    }
}
