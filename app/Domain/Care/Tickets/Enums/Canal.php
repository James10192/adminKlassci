<?php

namespace App\Domain\Care\Tickets\Enums;

enum Canal: string
{
    case InApp = 'IN_APP';
    case Manuel = 'MANUAL';
    case Email = 'EMAIL';
    case Api = 'API';
    case Supervision = 'AUTOMATED_MONITORING';
}
