<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandeHistorique extends Model
{
    protected $table = 'demande_historique';

    protected $fillable = [
        'demande_id',
        'acteur_agent_id',
        'acteur_nom',
        'acteur_role',
        'action',
        'ancien_statut',
        'nouveau_statut',
        'commentaire',
    ];

    /** Demande de permission concernée par cette étape. */
    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandePermission::class, 'demande_id');
    }

    /** Agent qui a réalisé cette étape. */
    public function acteur(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'acteur_agent_id');
    }
}
