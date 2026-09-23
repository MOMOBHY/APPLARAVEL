<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandePermission extends Model
{
    use HasFactory;

    protected $table = 'demandes_permission';

    public const EN_ATTENTE_GESTIONNAIRE_RH = 'EN_ATTENTE_GESTIONNAIRE_RH';

    public const EN_ATTENTE_VISA_SOUS_DIRECTEUR = 'EN_ATTENTE_VISA_SOUS_DIRECTEUR';

    public const EN_ATTENTE_VISA_DIRECTEUR = 'EN_ATTENTE_VISA_DIRECTEUR';

    public const EN_ATTENTE_DRH = 'EN_ATTENTE_DRH';

    public const RETOUR_CORRECTION = 'RETOUR_CORRECTION';

    public const VALIDEE = 'VALIDEE';

    public const REJETEE = 'REJETEE';

    protected $fillable = [
        'code_dossier', 'agent_id', 'type_permission_id', 'date_debut', 'date_fin',
        'nombre_jours', 'motif', 'piece_path', 'statut', 'gestionnaire_id',
        'avis_gestionnaire', 'visa_direction_id', 'visa_attendu', 'avis_direction',
        'decision_drh', 'motif_rejet', 'motif_retour', 'decideur_drh_id',
        'date_verif_rh', 'date_visa', 'date_decision', 'notifie_le', 'notifie_par_id',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'date_verif_rh' => 'datetime',
            'date_visa' => 'datetime',
            'date_decision' => 'datetime',
            'notifie_le' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TypePermission::class, 'type_permission_id');
    }

    public function historique(): HasMany
    {
        return $this->hasMany(DemandeHistorique::class, 'demande_id')->latest('id');
    }

    /** Cas 1 : circuit court avec visa hiérarchique obligatoire. */
    public function isCircuitCourt(): bool
    {
        return $this->nombre_jours <= 2;
    }
}
