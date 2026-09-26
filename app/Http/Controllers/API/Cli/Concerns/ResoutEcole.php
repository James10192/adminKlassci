<?php

namespace App\Http\Controllers\API\Cli\Concerns;

use App\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResoutEcole
{
    protected function ecole(string $code): Tenant
    {
        return Tenant::where('code', $code)->first()
            ?? throw new HttpException(404, "Aucun établissement avec le code « {$code} ».");
    }
}
