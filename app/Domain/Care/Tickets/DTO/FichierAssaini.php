<?php

namespace App\Domain\Care\Tickets\DTO;

/** Un fichier pret a stocker : ce qui sera ecrit, pas ce qui a ete recu. */
final class FichierAssaini
{
    public function __construct(
        public readonly string $contenu,
        public readonly string $mime,
        public readonly string $extension,
        public readonly string $nom,
        public readonly ?int $largeur = null,
        public readonly ?int $hauteur = null,
    ) {
    }

    public function empreinte(): string
    {
        return hash('sha256', $this->contenu);
    }
}
