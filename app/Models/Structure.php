<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Structure extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'nom', 'type', 'responsable_agent_id'];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'responsable_agent_id');
    }
}
