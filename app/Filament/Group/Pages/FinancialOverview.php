<?php

namespace App\Filament\Group\Pages;

use App\Services\TenantAggregationService;
use Filament\Pages\Page;

class FinancialOverview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Vue Financière';

    protected static ?string $navigationGroup = 'Analytiques';

    protected static ?string $title = 'Vue Financière';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.group.pages.financial-overview';

    /** Hero custom affiche le titre — Filament ne doit pas le répéter. */
    public function getHeading(): string
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function getFinancials(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);

        return $service->getGroupFinancials($group);
    }

    public function getTotals(): array
    {
        $financials = $this->getFinancials();

        $totalExpected = 0;
        $totalCollected = 0;

        foreach ($financials as $data) {
            $totalExpected += $data['revenue_expected'];
            $totalCollected += $data['revenue_collected'];
        }

        return [
            'expected' => $totalExpected,
            'collected' => $totalCollected,
            'outstanding' => max(0, $totalExpected - $totalCollected),
            'surplus' => max(0, $totalCollected - $totalExpected),
            'rate' => $totalExpected > 0 ? min(100, round(($totalCollected / $totalExpected) * 100, 1)) : 0,
        ];
    }
}
