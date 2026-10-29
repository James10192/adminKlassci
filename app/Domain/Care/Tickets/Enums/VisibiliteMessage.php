<?php

namespace App\Domain\Care\Tickets\Enums;

/**
 * Qui peut lire un message.
 *
 * Il n'y a volontairement pas de valeur par defaut cote personnel : l'ecran de
 * reponse oblige a choisir. Une note interne envoyee par megarde a une ecole
 * ne se rattrape pas.
 */
enum VisibiliteMessage: string
{
    case PublicClient = 'PUBLIC_TO_CUSTOMER';
    case Administration = 'SCHOOL_STAFF';
    case InterneSupport = 'INTERNAL_SUPPORT';
    case Technique = 'ENGINEERING_ONLY';
    case Securite = 'SECURITY_RESTRICTED';

    public function libelle(): string
    {
        return match ($this) {
            self::PublicClient => 'Répondre au demandeur',
            self::Administration => "Administration de l'établissement",
            self::InterneSupport => 'Note interne (support)',
            self::Technique => 'Note technique',
            self::Securite => 'Sécurité (restreint)',
        };
    }
}
