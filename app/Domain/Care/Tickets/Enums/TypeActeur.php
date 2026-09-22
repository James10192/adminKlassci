<?php

namespace App\Domain\Care\Tickets\Enums;

enum TypeActeur: string
{
    case Systeme = 'SYSTEM';
    case Personnel = 'STAFF';
    case Client = 'CUSTOMER';
}
