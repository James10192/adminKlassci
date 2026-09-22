<?php

namespace App\Domain\Care\Tickets\Enums;

/** Une decision produit, posee par un humain. Distincte de la severite. */
enum Priorite: string
{
    case P0 = 'P0';
    case P1 = 'P1';
    case P2 = 'P2';
    case P3 = 'P3';
    case P4 = 'P4';
}
