<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SaasMappingReviewEvent extends Model
{
    protected $table = 'saas_mapping_review_events';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(SaasMappingReviewRecord::class, 'saas_mapping_review_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
