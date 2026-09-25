<?php

namespace App\Domain\AssistantIa;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Copie, au master, d'une ligne `assistant_consommations` d'une école : ce qu'un
 * modèle d'IA a consommé dans un échange, et ce que ça a coûté. Jamais créée ici
 * autrement que par la synchronisation (tenant:sync-ai-usage).
 */
class ConsommationIa extends Model
{
    protected $table = 'tenant_ai_usages';

    protected $guarded = ['id'];

    protected $casts = [
        'cout_usd' => 'float',
        'cout_fcfa' => 'float',
        'cout_exact' => 'boolean',
        'survenue_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
