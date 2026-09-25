<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Information ou document partagé avec une, plusieurs ou toutes les structures du ministère. */
class Partage extends Model
{
    /** Rôles autorisés à partager. */
    public const ROLES_EMETTEURS = [
        'ROLE_DRH', 'ROLE_DIRECTEUR_CABINET', 'ROLE_DIRECTEUR', 'ROLE_SOUS_DIRECTEUR', 'ROLE_SECRETAIRE', 'ROLE_ADMIN_DSI',
    ];

    /** Rôles qui voient tous les partages, quelle que soit la structure. */
    private const ROLES_SUPERVISION = ['ROLE_ADMIN_DSI', 'ROLE_DRH', 'ROLE_DIRECTEUR_CABINET'];

    protected $fillable = ['auteur_agent_id', 'titre', 'message', 'toutes_structures', 'piece_id'];

    protected function casts(): array
    {
        return ['toutes_structures' => 'boolean'];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'auteur_agent_id');
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(PieceJointe::class, 'piece_id');
    }

    public function structures(): BelongsToMany
    {
        return $this->belongsToMany(Structure::class, 'partage_structure');
    }

    public static function peutPartager(User $user): bool
    {
        return $user->hasRole(...self::ROLES_EMETTEURS);
    }

    /** Partages visibles : les siens, ceux adressés à toutes les structures ou à la sienne ; supervision : tout. */
    public static function visiblesPour(User $user): Builder
    {
        $requete = static::query();
        if ($user->hasRole(...self::ROLES_SUPERVISION)) {
            return $requete;
        }
        $structureId = $user->agent?->structure_id;

        return $requete->where(fn (Builder $q) => $q
            ->where('toutes_structures', true)
            ->orWhere('auteur_agent_id', $user->agent_id)
            ->when($structureId, fn ($w) => $w->orWhereHas('structures', fn ($s) => $s->where('structures.id', $structureId))));
    }
}
