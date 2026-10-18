<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Note de service : document Word déposé par la secrétaire, validé par son directeur, puis envoyé aux
 * destinataires qu'elle choisit (e-mail et application). */
class NoteService extends Model
{
    protected $table = 'notes_service';

    /** Déposée par la secrétaire, pas encore transmise. */
    public const BROUILLON = 'BROUILLON';

    /** Transmise au directeur, en attente de sa décision. */
    public const EN_ATTENTE_VALIDATION = 'EN_ATTENTE_VALIDATION';

    /** Retournée par le directeur avec un motif : la secrétaire charge une version corrigée. */
    public const A_CORRIGER = 'A_CORRIGER';

    /** Validée par le directeur : la secrétaire peut l'envoyer. */
    public const VALIDEE = 'VALIDEE';

    /** Envoyée aux destinataires. */
    public const ENVOYEE = 'ENVOYEE';

    protected $fillable = [
        'numero',
        'objet',
        'date_note',
        'fichier_path',
        'fichier_nom',
        'fichier_taille',
        'statut',
        'secretaire_id',
        'directeur_id',
        'motif_retour',
        'envoi_a_tous',
        'transmise_le',
        'validee_le',
        'envoyee_le',
    ];

    /** Conversion automatique des colonnes (dates, booléens). */
    protected function casts(): array
    {
        return [
            'date_note' => 'date',
            'envoi_a_tous' => 'boolean',
            'transmise_le' => 'datetime',
            'validee_le' => 'datetime',
            'envoyee_le' => 'datetime',
        ];
    }

    /** Secrétaire qui a déposé la note. */
    public function secretaire(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'secretaire_id');
    }

    /** Directeur à qui la note a été transmise pour validation. */
    public function directeur(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'directeur_id');
    }

    /** Structures choisies à l'envoi. */
    public function structures(): BelongsToMany
    {
        return $this->belongsToMany(Structure::class, 'note_structure', 'note_id', 'structure_id');
    }

    /** Destinataires effectifs, figés à l'envoi. */
    public function destinataires(): HasMany
    {
        return $this->hasMany(NoteDestinataire::class, 'note_id');
    }

    /** Étapes de la note, de la plus ancienne à la plus récente. */
    public function historique(): HasMany
    {
        return $this->hasMany(NoteHistorique::class, 'note_id')->orderBy('id');
    }
}
