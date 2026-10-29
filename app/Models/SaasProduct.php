<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SaasProduct extends Model
{
    protected $fillable = ['code', 'name', 'establishment_type', 'adapter_key', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function establishments(): HasMany
    {
        return $this->hasMany(SaasEstablishment::class, 'product_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(SaasProductInstance::class, 'product_id');
    }
}
