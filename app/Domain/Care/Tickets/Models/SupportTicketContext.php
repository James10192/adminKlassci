<?php

namespace App\Domain\Care\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketContext extends Model
{
    protected $table = 'support_ticket_contexts';

    protected $fillable = [
        'ticket_id', 'route_name', 'url_path', 'module',
        'entity_type', 'entity_id', 'academic_year_id', 'class_id',
        'app_commit_sha', 'git_branch', 'deployment_id',
        'browser_family', 'browser_version', 'os_family', 'device_type',
        'viewport', 'locale', 'timezone', 'request_ids', 'extras', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'request_ids' => 'array',
            'extras' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
