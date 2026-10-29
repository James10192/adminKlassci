<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SaasEstablishment extends Model
{
    protected $fillable = ['organization_id', 'name', 'slug', 'contact_email'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(SaasOrganization::class, 'organization_id');
    }

    public function productInstances(): HasMany
    {
        return $this->hasMany(SaasProductInstance::class, 'establishment_id');
    }
}
