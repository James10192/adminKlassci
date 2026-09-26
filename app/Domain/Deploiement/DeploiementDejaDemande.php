<?php

namespace App\Domain\Deploiement;

use RuntimeException;

final class DeploiementDejaDemande extends RuntimeException
{
    public function __construct(public readonly string $ecole)
    {
        parent::__construct("Un déploiement de {$ecole} est déjà demandé ou en cours.");
    }
}
