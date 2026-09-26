<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoteHistorique extends Model
{
    protected $table = 'note_historique';

    protected $fillable = [
        'note_id',
        'acteur_agent_id',
        'acteur_nom',
        'acteur_role',
        'action',
        'ancien_statut',
        'nouveau_statut',
        'commentaire',
    ];
}
