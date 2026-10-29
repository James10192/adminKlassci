<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class SaasEstablishment extends Model
{
    protected $fillable = [
        'organization_id',
        'product_id',
        'name',
        'slug',
        'contact_email',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(SaasOrganization::class, 'organization_id');
    }

    /** Produit/categorie unique de l'etablissement, non du groupe. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(SaasProduct::class, 'product_id');
    }

    public function productInstance(): HasOne
    {
        return $this->hasOne(SaasProductInstance::class, 'establishment_id');
    }
}
