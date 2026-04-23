<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'target_segment',
        'monthly_fee',
        'first_year_fee',
        'annual_fee',
        'whatsapp_types',
        'sla_response_hours',
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
        'first_year_fee'            => 'integer',
        'annual_fee'                => 'integer',
        'whatsapp_types'            => 'integer',
        'sla_response_hours'        => 'integer',
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

    public function getFormattedFirstYearFeeAttribute(): string
    {
        return number_format($this->first_year_fee, 0, ',', ' ') . ' FCFA';
    }

    public function getFormattedAnnualFeeAttribute(): string
    {
        return number_format($this->annual_fee, 0, ',', ' ') . ' FCFA';
    }

    /**
     * Libellé SLA pour affichage. Les paliers sont ceux de la grille
     * Signature 2026 : 2h (ELITE), 4h (PRO), 24h = J+1 (Essentiel).
     */
    public function getSlaLabelAttribute(): string
    {
        if ($this->sla_response_hours === null) {
            return 'Best effort';
        }

        return match (true) {
            $this->sla_response_hours <= 2 => '2h (6j/7)',
            $this->sla_response_hours <= 4 => '4h (5j/7)',
            $this->sla_response_hours <= 24 => 'J+1 (jours ouvrables)',
            default => "{$this->sla_response_hours}h",
        };
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
