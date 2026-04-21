<?php

namespace App\Filament\Group\Concerns;

/**
 * Suppresses Filament's default page heading + subheading rendering so a
 * custom hero (rendered via `getHeader()` on the page) is the only title
 * visible.
 *
 * Applied to every group-portal page that ships a `<x-group-hero>` — without
 * this trait Filament renders its own `<h1>` above the hero, causing a
 * visible duplicate.
 */
trait HasCustomHero
{
    public function getHeading(): string
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }
}
