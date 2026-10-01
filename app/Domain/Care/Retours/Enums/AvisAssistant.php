<?php

namespace App\Domain\Care\Retours\Enums;

/** Ce qu'une personne de l'école a pensé d'une réponse de Nanan. */
enum AvisAssistant: string
{
    case Utile = 'utile';
    case PasUtile = 'pas_utile';

    public function libelle(): string
    {
        return match ($this) {
            self::Utile => 'Utile',
            self::PasUtile => 'Pas utile',
        };
    }

    public function ton(): string
    {
        return match ($this) {
            self::Utile => 'success',
            self::PasUtile => 'danger',
        };
    }

    public function pictogramme(): string
    {
        return match ($this) {
            self::Utile => '👍',
            self::PasUtile => '👎',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
