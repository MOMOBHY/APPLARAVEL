<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'matricule',
        'agent_id',
        'structure_id',
        'actif',
        'derniere_connexion',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** Conversion automatique des colonnes (dates, booléens, mot de passe haché). */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'derniere_connexion' => 'datetime',
            'actif' => 'boolean',
        ];
    }

    /** Agent auquel appartient ce compte. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** Structure du compte. */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class);
    }

    /** Rôles du compte. */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    /** Indique si le compte possède au moins un des rôles donnés (codes comme ROLE_DRH). */
    public function hasRole(string ...$codes): bool
    {
        return $this->roles()->whereIn('code', $codes)->exists();
    }

    /** Indique si un des rôles du compte accorde le privilège donné. */
    public function hasPrivilege(string $code): bool
    {
        return $this->roles()->whereHas('privileges', fn ($q) => $q->where('code', $code))->exists();
    }
}
