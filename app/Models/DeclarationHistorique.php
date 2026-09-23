<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeclarationHistorique extends Model
{
    protected $table = 'declaration_historique';

    protected $fillable = [
        'type_dossier', 'dossier_id', 'code_dossier', 'acteur_agent_id', 'acteur_nom',
        'acteur_role', 'action', 'ancien_statut', 'nouveau_statut', 'commentaire',
    ];
}
