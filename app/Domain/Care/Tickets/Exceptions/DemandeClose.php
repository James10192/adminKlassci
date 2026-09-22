<?php

namespace App\Domain\Care\Tickets\Exceptions;

use DomainException;

/** L'ecole repond a une demande close, rejetee ou fusionnee : elle doit en ouvrir une nouvelle. */
class DemandeClose extends DomainException
{
}
