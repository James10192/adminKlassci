<?php

namespace App\Domain\Care\Acces\Services;

use App\Models\User;

/**
 * Ce qu'un membre du personnel KLASSCI peut faire dans KLASSCI Care.
 *
 * Lu depuis config/care.php. Les Gates sont definies a partir de cette liste
 * (CareServiceProvider) : le code demande `Gate::allows('support.tickets.manage')`
 * et n'ecrit jamais le nom d'un role.
 */
class Capacites
{
    /** @return list<string> */
    public static function toutes(): array
    {
        return array_keys(config('care.capacites', []));
    }

    public static function accorde(?User $user, string $capacite): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }

        $accordees = config('care.capacites_par_role.'.$user->role, []);

        return in_array('*', $accordees, true) || in_array($capacite, $accordees, true);
    }
}
