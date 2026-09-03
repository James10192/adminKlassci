<?php

namespace App\Enums;

enum AlertSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    /**
     * Le mot francais, pour les surfaces qui n'ont pas de couleur a leur
     * disposition — un tableau imprime, une cellule de tableur. A l'ecran, la
     * gravite se lit a la couleur du badge ; sur papier, elle doit s'ecrire.
     */
    public function libelle(): string
    {
        return match ($this) {
            self::Critical => 'Critique',
            self::Warning => 'À surveiller',
            self::Info => 'Information',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
