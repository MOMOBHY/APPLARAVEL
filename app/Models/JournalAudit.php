<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/** Journal d'audit : une ligne par entrée, sortie ou action sensible. Jamais modifié, jamais supprimé par l'application. */
class JournalAudit extends Model
{
    protected $table = 'journal_audit';

    public const UPDATED_AT = null;

    public const CONNEXION = 'CONNEXION';

    public const DOSSIER = 'DOSSIER';

    public const COMPTE = 'COMPTE';

    public const MOT_DE_PASSE = 'MOT_DE_PASSE';

    public const PIECE = 'PIECE';

    protected $fillable = [
        'user_id',
        'matricule',
        'nom',
        'role',
        'categorie',
        'action',
        'description',
        'reference',
        'reussi',
        'ip',
        'appareil',
    ];

    /** Conversion automatique des colonnes (dates, booléens, mot de passe haché). */
    protected function casts(): array
    {
        return ['reussi' => 'boolean', 'created_at' => 'datetime'];
    }

    /**
     * Enregistre un événement. Ne lève jamais d'exception : l'audit ne doit pas bloquer l'application.
     * Sans utilisateur (connexion refusée), $matricule garde la valeur saisie.
     */
    public static function noter(
        string $categorie,
        string $action,
        ?User $user = null,
        ?string $description = null,
        ?string $reference = null,
        bool $reussi = true,
        ?string $matricule = null,
    ): void {
        try {
            $requete = request();
            $user?->loadMissing('roles');
            self::create([
                'user_id' => $user?->id,
                'matricule' => $user?->matricule ??
                    ($matricule !== null ? Str::limit(strtoupper(trim($matricule)), 30, '') : null),
                'nom' => $user?->name,
                'role' => $user?->roles->first()?->code,
                'categorie' => $categorie,
                'action' => $action,
                'description' => $description !== null ? Str::limit($description, 500, '') : null,
                'reference' => $reference,
                'reussi' => $reussi,
                'ip' => $requete->ip(),
                'appareil' => Str::limit((string) $requete->userAgent(), 250, ''),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
