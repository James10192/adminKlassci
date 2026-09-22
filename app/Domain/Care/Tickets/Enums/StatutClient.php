<?php

namespace App\Domain\Care\Tickets\Enums;

/**
 * Ce que l'ecole voit de sa demande.
 *
 * Sept etats, pas vingt : une secretaire n'a pas a savoir qu'une demande est
 * « ESCALATED_ENGINEERING » plutot que « TRIAGED ». Elle a besoin de savoir si
 * on s'en occupe, et si on attend quelque chose d'elle.
 */
enum StatutClient: string
{
    case Recu = 'RECU';
    case EnAnalyse = 'EN_ANALYSE';
    case ActionRequise = 'ACTION_REQUISE';
    case EnResolution = 'EN_RESOLUTION';
    case CorrectionDeployee = 'CORRECTION_DEPLOYEE';
    case Resolu = 'RESOLU';
    case Ferme = 'FERME';

    public function libelle(): string
    {
        return match ($this) {
            self::Recu => 'Reçue',
            self::EnAnalyse => 'En analyse',
            self::ActionRequise => 'Action requise de votre part',
            self::EnResolution => 'En cours de résolution',
            self::CorrectionDeployee => 'Correction déployée',
            self::Resolu => 'Résolue',
            self::Ferme => 'Fermée',
        };
    }
}
