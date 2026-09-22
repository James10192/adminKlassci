<?php

namespace App\Domain\Care\Tickets\DTO;

use App\Domain\Care\Tickets\Models\SupportTicket;

final class ResultatCreation
{
    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly bool $rejoue,
    ) {
    }
}
