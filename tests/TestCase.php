<?php

namespace Tests;

use App\Filament\Group\Resources\EstablishmentResource;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Purge les mémos statiques entre deux tests.
     *
     * Un worker Pest enchaîne les tests dans le MÊME processus PHP : un mémo
     * statique survit donc d'un test à l'autre, et un test peut lire les
     * indicateurs d'un établissement créé par le précédent. Inoffensif sous
     * PHP-FPM (un processus = une requête), pas ici.
     */
    protected function setUp(): void
    {
        parent::setUp();

        EstablishmentResource::forgetKpisMemo();
    }
}
