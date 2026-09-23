<?php

namespace App\Domain\Care\Tickets\Services;

use App\Domain\Care\Tickets\Enums\TypeActeur;
use App\Models\User;

/** Qui agit : le systeme, un membre du personnel KLASSCI, ou une personne de l'ecole. */
final class Acteur
{
    private function __construct(
        public readonly TypeActeur $type,
        public readonly ?string $reference,
        public readonly ?string $nom = null,
    ) {
    }

    public static function systeme(): self
    {
        return new self(TypeActeur::Systeme, null, 'KLASSCI Care');
    }

    public static function personnel(User $user): self
    {
        return new self(TypeActeur::Personnel, (string) $user->id, $user->name);
    }

    public static function client(int $idExterne, ?string $nom = null): self
    {
        return new self(TypeActeur::Client, (string) $idExterne, $nom);
    }
}
