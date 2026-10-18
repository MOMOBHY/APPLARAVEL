<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Étape d'une note de service : dépôt, transmission, validation, retour, nouvelle version, envoi. */
class NoteHistorique extends Model
{
    protected $table = 'note_historique';

    protected $fillable = [
        'note_id',
        'acteur_agent_id',
        'acteur_nom',
        'acteur_role',
        'action',
        'commentaire',
    ];
}
