<?php

namespace App\Filament\Group\Pages;

use App\Filament\Group\Concerns\HasCustomHero;
use App\Services\TenantAggregationService;
use Filament\Pages\Page;

class FinancialOverview extends Page
{
    use HasCustomHero;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Vue Financière';

    protected static ?string $navigationGroup = 'Analytiques';

    protected static ?string $title = 'Vue Financière';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.group.pages.financial-overview';

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
