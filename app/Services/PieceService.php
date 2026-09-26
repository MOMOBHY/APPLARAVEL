<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\PieceJointe;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Dépôt des pièces justificatives sur disque privé + fiche en base
 * (nom, MIME, chemin, date, dossier). Accès via route contrôlée uniquement.
 */
class PieceService
{
    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png'];

    public const MAX_KO = 5120;

    /**
     * Enregistre un justificatif sur le disque privé et crée sa fiche (nom, type, chemin, dossier
     * concerné, auteur).
     */
    public static function deposer(
        UploadedFile $fichier,
        string $dossierType,
        ?int $dossierId,
        ?string $reference,
        ?Agent $auteur,
    ): PieceJointe {
        $chemin = $fichier->store("pieces/{$dossierType}", 'local');

        return PieceJointe::create([
            'dossier_type' => $dossierType,
            'dossier_id' => $dossierId,
            'reference_dossier' => $reference,
            'nom_fichier' => $fichier->getClientOriginalName(),
            'type_mime' => $fichier->getClientMimeType(),
            'chemin_stockage' => $chemin,
            'televerse_par_id' => $auteur?->id,
        ]);
    }

    /**
     * Indique si l'utilisateur a le droit d'ouvrir une pièce : administrateur, auteur de la pièce, ou
     * rôle habilité selon le type de dossier.
     */
    public static function autorise(User $user, PieceJointe $piece): bool
    {
        if ($user->hasRole('ROLE_ADMIN_DSI')) {
            return true;
        }
        if ($piece->televerse_par_id && $piece->televerse_par_id === $user->agent_id) {
            return true;
        }

        return match ($piece->dossier_type) {
            'permission' => $user->hasRole('ROLE_GESTIONNAIRE_RH', 'ROLE_DRH'),
            'naissance', 'deces' => $user->hasRole('ROLE_GESTIONNAIRE_RH', 'ROLE_DRH'),
            'note' => $user->hasRole(
                'ROLE_SECRETAIRE',
                'ROLE_DRH',
                'ROLE_DIRECTEUR',
                'ROLE_SOUS_DIRECTEUR',
            ),
            default => false,
        };
    }
}
