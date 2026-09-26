<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeclarationDeces extends Model
{
    protected $table = 'declarations_deces';

    protected $fillable = [
        'code_dossier',
        'agent_id',
        'nom_defunt',
        'prenom_defunt',
        'lien_parente',
        'date_deces',
        'lieu_deces',
        'certificat_path',
        'statut',
        'motif_rejet',
        'motif_retour',
        'valideur_id',
        'validated_at',
    ];

    /** Conversion automatique des colonnes (dates, booléens, mot de passe haché). */
    protected function casts(): array
    {
        return ['date_deces' => 'date', 'validated_at' => 'datetime'];
    }

    /** Agent qui a déclaré le décès. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
