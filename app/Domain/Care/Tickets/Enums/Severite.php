<?php

namespace App\Domain\Care\Tickets\Enums;

/** L'impact. Distinct de la priorite, qui est une decision. */
enum Severite: string
{
    case Sev0 = 'SEV0';
    case Sev1 = 'SEV1';
    case Sev2 = 'SEV2';
    case Sev3 = 'SEV3';
    case Sev4 = 'SEV4';

    public function libelle(): string
    {
        return match ($this) {
            self::Sev0 => 'SEV0 — catastrophique',
            self::Sev1 => 'SEV1 — critique',
            self::Sev2 => 'SEV2 — significatif',
            self::Sev3 => 'SEV3 — modéré',
            self::Sev4 => 'SEV4 — mineur',
        };
    }

    public function ton(): string
    {
        return match ($this) {
            self::Sev0, self::Sev1 => 'danger',
            self::Sev2 => 'warning',
            default => 'gray',
        };
    }
}
