<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SaasProductInstance extends Model
{
    protected $fillable = [
        'establishment_id',
        'product_id',
        'legacy_tenant_id',
        'external_tenant_code',
        'base_url',
        'status',
        'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(SaasEstablishment::class, 'establishment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SaasProduct::class, 'product_id');
    }

    public function legacyTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'legacy_tenant_id');
    }
}
