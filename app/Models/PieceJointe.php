<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PieceJointe extends Model
{
    protected $table = 'pieces_jointes';

    public const TYPES = ['permission', 'naissance', 'deces', 'note', 'partage'];

    protected $fillable = [
        'dossier_type', 'dossier_id', 'reference_dossier', 'nom_fichier',
        'type_mime', 'chemin_stockage', 'televerse_par_id',
    ];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'televerse_par_id');
    }
}
