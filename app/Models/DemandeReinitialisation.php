<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande de réinitialisation de mot de passe, soumise à l'autorisation de l'administrateur. */
class DemandeReinitialisation extends Model
{
    public const EN_ATTENTE = 'EN_ATTENTE';

    public const AUTORISEE = 'AUTORISEE';

    public const REFUSEE = 'REFUSEE';

    public const UTILISEE = 'UTILISEE';

    public const ANNULEE = 'ANNULEE';

    protected $table = 'demandes_reinitialisation';

    protected $fillable = [
        'user_id',
        'code_hash',
        'statut',
        'traite_par_id',
        'traite_le',
        'utilise_le',
    ];

    protected $hidden = ['code_hash'];

    /** Conversion automatique des colonnes (dates, booléens, mot de passe haché). */
    protected function casts(): array
    {
        return ['traite_le' => 'datetime', 'utilise_le' => 'datetime'];
    }

    /** Utilisateur qui a demandé la réinitialisation. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
