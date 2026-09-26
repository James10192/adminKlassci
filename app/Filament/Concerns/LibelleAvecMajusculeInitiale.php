<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Str;

/**
 * Titres de ressource à la française : une majuscule initiale, pas une par mot.
 *
 * Filament passe les libellés par Str::ucwords pour ses titres de page et ses
 * boutons, ce qui donnait « Journal D'activité », « Plans D'abonnement »,
 * « Demandes De Support ».
 */
trait LibelleAvecMajusculeInitiale
{
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
