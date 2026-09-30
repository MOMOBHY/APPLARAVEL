<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'matricule',
        'civilite',
        'nom',
        'prenom',
        'date_naissance',
        'sexe',
        'telephone',
        'email',
        'solde_permission_annuel',
        'structure_id',
        'fonction_id',
    ];

    /** Conversion automatique des colonnes (dates, booléens, mot de passe haché). */
    protected function casts(): array
    {
        return ['date_naissance' => 'date'];
    }

    /** Structure à laquelle appartient l'agent. */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    /** Fonction occupée par l'agent. */
    public function fonction(): BelongsTo
    {
        return $this->belongsTo(Fonction::class);
    }

    /** Compte utilisateur de l'agent, s'il en a un. */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** Nom complet affiché : civilité, nom et prénom. */
    public function fullName(): string
    {
        return trim("{$this->civilite} {$this->nom} {$this->prenom}");
    }

    /** Vrai si l'agent est lui-même sous-directeur ou directeur : son dossier ne peut pas attendre sa
     * propre conformité, il part directement au DRH. */
    public function autoriteVisa(): bool
    {
        return $this->roleVisa() !== null;
    }

    /** Niveau de visa porté par l'agent, ou null s'il n'en porte aucun. Un compte peut cumuler les deux
     * rôles : le niveau le plus élevé l'emporte, car c'est lui qui signe en dernier. */
    public function roleVisa(): ?string
    {
        $user = $this->user;
        if (! $user) {
            return null;
        }
        if ($user->hasRole('ROLE_DIRECTEUR')) {
            return 'DIRECTEUR';
        }

        return $user->hasRole('ROLE_SOUS_DIRECTEUR') ? 'SOUS_DIRECTEUR' : null;
    }
}
