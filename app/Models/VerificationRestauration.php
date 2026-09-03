<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une restauration réellement essayée, et ce qu'on en a relu.
 *
 * Existe pour qu'une date puisse être citée. « Nos sauvegardes sont testées »
 * sans date ne veut rien dire.
 */
class VerificationRestauration extends Model
{
    protected $table = 'verifications_restauration';

    protected $fillable = [
        'tenant_id',
        'tenant_backup_id',
        'verdict',
        'raison',
        'tables_restaurees',
        'lignes_par_table',
        'duree_secondes',
        'verifiee_at',
    ];

    protected $casts = [
        'lignes_par_table' => 'array',
        'tables_restaurees' => 'integer',
        'duree_secondes' => 'integer',
        'verifiee_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sauvegarde(): BelongsTo
    {
        return $this->belongsTo(TenantBackup::class, 'tenant_backup_id');
    }

    /** La dernière restauration réussie, toutes instances confondues. */
    public static function derniereReussie(): ?self
    {
        return static::where('verdict', 'reussie')->latest('verifiee_at')->first();
    }
}
