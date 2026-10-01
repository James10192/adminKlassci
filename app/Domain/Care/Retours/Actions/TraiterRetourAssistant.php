<?php

namespace App\Domain\Care\Retours\Actions;

use App\Domain\Care\Retours\Models\RetourAssistant;
use App\Models\User;

/** Un membre de l'équipe a lu le retour et fait ce qu'il fallait. */
class TraiterRetourAssistant
{
    public function executer(RetourAssistant $retour, User $par, ?string $note): RetourAssistant
    {
        $note = $note !== null ? trim($note) : null;

        $retour->forceFill([
            'traite_le' => now(),
            'traite_par' => $par->id,
            'note_interne' => $note !== '' ? $note : $retour->note_interne,
        ])->save();

        return $retour;
    }
}
