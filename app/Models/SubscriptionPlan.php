<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'monthly_fee',
        'max_users',
        'max_staff',
        'max_students',
        'max_inscriptions_per_year',
        'max_storage_mb',
        'features',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'monthly_fee'               => 'integer',
        'max_users'                 => 'integer',
        'max_staff'                 => 'integer',
        'max_students'              => 'integer',
        'max_inscriptions_per_year' => 'integer',
        'max_storage_mb'            => 'integer',
        'features'                  => 'array',
        'is_active'                 => 'boolean',
        'sort_order'                => 'integer',
    ];

    public function tenants()
    {
        return $this->hasMany(Tenant::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('monthly_fee');
    }

    public function getFormattedFeeAttribute(): string
    {
        return number_format($this->monthly_fee, 0, ',', ' ') . ' FCFA';
    }

    public function getLimitsAttribute(): array
    {
        return [
            'max_users'                 => $this->max_users,
            'max_staff'                 => $this->max_staff,
            'max_students'              => $this->max_students,
            'max_inscriptions_per_year' => $this->max_inscriptions_per_year,
            'max_storage_mb'            => $this->max_storage_mb,
        ];
    }
}
