<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Agent destinataire d'une note de service envoyée (lue ou non). */
class NoteDestinataire extends Model
{
    protected $table = 'note_destinataires';

    protected $fillable = ['note_id', 'agent_id', 'choisi', 'lu_le'];

    /** Conversion automatique des colonnes (dates, booléens). */
    protected function casts(): array
    {
        return ['choisi' => 'boolean', 'lu_le' => 'datetime'];
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(NoteService::class, 'note_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
