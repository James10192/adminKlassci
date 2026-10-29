<?php

namespace App\Support\Group;

/** Same login-identifier grammar across generated names, Filament and service. */
final class MemberUsernameRules
{
    // Accepte les points créés par UsernameGenerator (prenom.nom), tirets et underscores.
    // Aucun espace ou caractère de contrôle dans les identifiants de connexion.
    public const PATTERN = '/\A[a-zA-Z0-9_.-]+\z/D';
}
