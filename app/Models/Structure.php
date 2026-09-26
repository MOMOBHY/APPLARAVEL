<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Structure extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'nom',
        'sigle',
        'type',
        'parent_id',
        'officielle',
        'responsable_agent_id',
    ];

    /** Conversion automatique des colonnes (dates, booléens, mot de passe haché). */
    protected function casts(): array
    {
        return ['officielle' => 'boolean'];
    }

    /** Structure dont celle-ci dépend (hiérarchie du ministère). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'parent_id');
    }

    /** Agents rattachés à la structure. */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /** Agent responsable de la structure : il donne le visa sur les permissions de ses agents. */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'responsable_agent_id');
    }
}
