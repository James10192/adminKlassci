<?php

namespace App\Domain\Care\Tickets\Exceptions;

use RuntimeException;

/** Le fichier n'est pas acceptable : type, taille ou contenu. Le message est montrable. */
class PieceJointeRefusee extends RuntimeException
{
}
