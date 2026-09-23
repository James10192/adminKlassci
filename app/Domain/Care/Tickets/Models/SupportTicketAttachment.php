<?php

namespace App\Domain\Care\Tickets\Models;

use App\Domain\Care\Tickets\Enums\TypeActeur;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketAttachment extends Model
{
    protected $table = 'support_ticket_attachments';

    protected $fillable = [
        'ticket_id', 'author_type', 'author_ref', 'author_name', 'visibility',
        'original_name', 'mime', 'size_bytes', 'width', 'height', 'disk', 'path', 'sha256', 'client_key',
    ];

    protected function casts(): array
    {
        return [
            'author_type' => TypeActeur::class,
            'visibility' => VisibiliteMessage::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function estImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
