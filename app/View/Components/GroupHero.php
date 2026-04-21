<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Premium hero component used across group portal pages (Dashboard,
 * FinancialOverview, Benchmarking, Establishment views).
 *
 * Namespaced under the existing `gp-*` CSS family — no new token family.
 *
 * Slots :
 *   actions — buttons on the right of row 1 (glass / white)
 *   kpis    — 4-6 KPI cards in row 2 (glass background, see .gp-hero-kpi)
 *   badges  — small badges under the title (period chip, status, etc.)
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
