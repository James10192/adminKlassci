<?php

namespace App\Domain\Care\Retours\DTO;

use App\Domain\Care\Retours\Models\RetourAssistant;

final class ResultatRetour
{
    public function __construct(
        public readonly RetourAssistant $retour,
        // La même clé a déjà été reçue : rien n'a été écrit.
        public readonly bool $rejoue,
    ) {
    }
}
