<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NoteService extends Model
{
    use HasFactory;

    protected $table = 'notes_service';

    public const BROUILLON = 'BROUILLON';

    public const EN_ATTENTE_SAISIE = 'EN_ATTENTE_SAISIE';

    public const EN_ATTENTE_VALIDATION = 'EN_ATTENTE_VALIDATION';

    public const VALIDEE = 'VALIDEE';

    public const REJETEE = 'REJETEE';

    public const DIFFUSEE = 'DIFFUSEE';

    public const ARCHIVEE = 'ARCHIVEE';

    protected $fillable = [
        'numero_reference', 'objet', 'contenu', 'fichier_path', 'signataire_id',
        'secretaire_id', 'statut', 'date_emission', 'date_diffusion',
        'valide_le', 'valideur_id',
    ];

    protected function casts(): array
    {
        return ['date_emission' => 'date', 'date_diffusion' => 'datetime', 'valide_le' => 'datetime'];
    }

    public function structures(): BelongsToMany
    {
        return $this->belongsToMany(Structure::class, 'note_structure', 'note_id', 'structure_id');
    }

    public function signataire(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'signataire_id');
    }

    public function historique(): HasMany
    {
        return $this->hasMany(NoteHistorique::class, 'note_id')->latest('id');
    }
}
