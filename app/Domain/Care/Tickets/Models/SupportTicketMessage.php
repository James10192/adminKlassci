<?php

namespace App\Domain\Care\Tickets\Models;

use App\Domain\Care\Tickets\Enums\TypeActeur;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketMessage extends Model
{
    protected $table = 'support_ticket_messages';

    protected $fillable = ['ticket_id', 'author_type', 'author_ref', 'author_name', 'visibility', 'body', 'client_key'];

    protected function casts(): array
    {
        return [
            'author_type' => TypeActeur::class,
            'visibility' => VisibiliteMessage::class,
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
