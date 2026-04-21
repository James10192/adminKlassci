<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Premium hero used across group portal pages. CSS lives in `gp-hero-*`
 * (public/css/groupe-portal.css).
 *
 * Slots:
 *   actions — buttons on the right of row 1
 *   kpis    — 4-6 KPI cards in row 2 (glass, see .gp-hero-kpi)
 *   badges  — chips/badges under the subtitle
 */
class GroupHero extends Component
{
    public function __construct(
        public string $title,
        public ?string $subtitle = null,
        public ?string $iconPath = null,
    ) {}

    public function render(): View
    {
        return view('components.group-hero');
    }
}
