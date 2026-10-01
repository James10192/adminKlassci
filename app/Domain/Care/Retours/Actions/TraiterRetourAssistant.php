<?php

namespace App\Domain\Care\Retours\Actions;

use App\Domain\Care\Retours\Models\RetourAssistant;
use App\Models\User;

/**
 * Un membre de l'équipe a lu le retour et fait ce qu'il fallait.
 *
 * La note interne se complète, elle ne s'écrase pas : ce qu'un collègue a
 * consigné avant reste lisible.
 */
class TraiterRetourAssistant
{
    public function executer(RetourAssistant $retour, User $par, ?string $note): RetourAssistant
    {
        $note = $note !== null ? trim($note) : '';

        $retour->forceFill([
            'traite_le' => $retour->traite_le ?? now(),
            'traite_par' => $retour->traite_par ?? $par->id,
            'note_interne' => $note === '' ? $retour->note_interne
                : trim(($retour->note_interne ? $retour->note_interne."\n\n" : '')."{$par->name} : {$note}"),
        ])->save();

        return $retour;
    }
}
