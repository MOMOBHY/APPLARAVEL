<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Structure extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'nom', 'sigle', 'type', 'parent_id', 'officielle', 'responsable_agent_id'];

    protected function casts(): array
    {
        return ['officielle' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'parent_id');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'responsable_agent_id');
    }
}
