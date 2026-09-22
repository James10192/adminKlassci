<?php

namespace App\Domain\Care\Tickets\Enums;

enum TypeEvenement: string
{
    case TicketCree = 'TICKET_CREATED';
    case ContexteJoint = 'CONTEXT_ATTACHED';
    case StatutChange = 'STATUS_CHANGED';
    case Classe = 'CLASSIFIED';
    case SeveriteChangee = 'SEVERITY_CHANGED';
    case PrioriteChangee = 'PRIORITY_CHANGED';
    case Assigne = 'ASSIGNED';
    case ReponseSupport = 'SUPPORT_REPLIED';
    case NoteInterne = 'INTERNAL_NOTE_ADDED';
    case ReponseClient = 'CUSTOMER_REPLIED';
}
