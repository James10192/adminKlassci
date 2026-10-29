<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SaasMappingReviewRecord extends Model
{
    protected $table = 'saas_mapping_reviews';

    // Ecritures uniquement via SaasMappingWorkflow, pas de mass assignment.
    protected $guarded = ['*'];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'revision' => 'integer',
    ];

    public function legacyTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'legacy_tenant_id')->withTrashed();
    }

    public function proposedGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'proposed_group_id')->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SaasProduct::class, 'product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SaasMappingReviewEvent::class, 'saas_mapping_review_id')->orderBy('id');
    }
}
