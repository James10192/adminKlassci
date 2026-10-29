<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SaasOrganization extends Model
{
    protected $fillable = ['name', 'country_code'];

    public function establishments(): HasMany
    {
        return $this->hasMany(SaasEstablishment::class, 'organization_id');
    }
}
