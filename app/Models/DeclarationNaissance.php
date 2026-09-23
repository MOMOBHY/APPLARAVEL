<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeclarationNaissance extends Model
{
    protected $table = 'declarations_naissance';

    protected $fillable = [
        'code_dossier', 'agent_id', 'nom_enfant', 'prenom_enfant',
        'date_naissance_enfant', 'lieu_naissance_enfant', 'extrait_path',
        'statut', 'motif_rejet', 'motif_retour', 'valideur_id', 'validated_at',
    ];

    protected function casts(): array
    {
        return ['date_naissance_enfant' => 'date', 'validated_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
