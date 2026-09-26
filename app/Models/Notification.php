<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['agent_id', 'titre', 'message', 'type', 'reference_dossier', 'est_lu'];

    /** Conversion automatique des colonnes (dates, booléens, mot de passe haché). */
    protected function casts(): array
    {
        return ['est_lu' => 'boolean'];
    }
}
