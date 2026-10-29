<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SaasOrganization extends Model
{
    protected $fillable = ['name', 'country_code', 'legacy_group_id'];

    /** Groupe historique, facultatif : ne rien deviner a partir du nom. */
    public function legacyGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'legacy_group_id');
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(SaasEstablishment::class, 'organization_id');
    }
}
