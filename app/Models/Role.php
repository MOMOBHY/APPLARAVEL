<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['code', 'libelle', 'description'];

    /** Privilèges accordés à ce rôle. */
    public function privileges(): BelongsToMany
    {
        return $this->belongsToMany(Privilege::class, 'role_privilege');
    }

    /** Comptes qui portent ce rôle. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }
}
