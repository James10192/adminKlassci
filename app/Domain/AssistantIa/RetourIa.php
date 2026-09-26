<?php

namespace App\Domain\AssistantIa;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Copie au master d'un avis 👍 / 👎 d'une école sur une réponse de Nanan. */
class RetourIa extends Model
{
    protected $table = 'tenant_ai_feedbacks';

    protected $guarded = ['id'];

    protected $casts = [
        'survenue_at' => 'datetime',
        'modifie_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
