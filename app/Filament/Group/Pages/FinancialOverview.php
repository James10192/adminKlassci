<?php

namespace App\Filament\Group\Pages;

use App\Contracts\Group\GroupPayrollProviderInterface;
use App\Domain\Exports\Reports\ConsolidationFinanciereReport;
use App\Domain\Exports\Reports\MasseSalarialeReport;
use App\Filament\Group\Concerns\HasCustomHero;
use App\Filament\Group\Concerns\HasReportActions;
use App\Services\TenantAggregationService;
use Filament\Pages\Page;

class FinancialOverview extends Page
{
    use HasCustomHero;
    use HasReportActions;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Vue Financière';

    protected static ?string $navigationGroup = 'Analytiques';

    protected static ?string $title = 'Vue Financière';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.group.pages.financial-overview';

    /**
     * Consolidation financière du groupe.
     *
     * Mémoïsée : la vue l'interroge à plusieurs endroits et chaque appel
     * traverse toutes les bases établissement.
     *
     * @var array<string,mixed>|null
     */
    private ?array $financials = null;

    /** @return array<string,mixed> */
    public function getFinancials(): array
    {
        return $this->financials ??= app(TenantAggregationService::class)
            ->getGroupFinancials(auth('group')->user()->group);
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

    /**
     * Masse salariale enseignante du groupe.
     *
     * Mémoïsée : la vue l'interroge plusieurs fois, et chaque appel consolide
     * tous les établissements.
     *
     * @var array<string,mixed>|null
     */
    private ?array $paie = null;

    /** @return array<string,mixed> */
    public function getPayroll(): array
    {
        return $this->paie ??= app(GroupPayrollProviderInterface::class)
            ->computeGroupPayroll(auth('group')->user()->group);
    }

    /**
     * Ce qu'il reste une fois les enseignants payés.
     *
     * Le coût retenu est la masse BRUTE : l'école verse le net à l'enseignant
     * et reverse les retenues (ITS, CNPS) à l'État. Ne compter que le net
     * sous-estimerait la sortie réelle.
     *
     * `complet` dit si tous les établissements ont répondu. Un groupe dont une
     * base est injoignable affiche un résultat trop favorable ; l'écran doit
     * pouvoir le signaler plutôt que de laisser croire au chiffre.
     *
     * @return array{cout: float, net: float, complet: bool, manquants: int}
     */
    public function getResultat(): array
    {
        $paie = $this->getPayroll();
        $encaisse = (float) ($this->getTotals()['collected'] ?? 0);
        $cout = (float) $paie['masse_brute'];

        $manquants = 0;
        foreach ($paie['establishments'] as $etablissement) {
            if ($etablissement['error'] ?? false) {
                $manquants++;
            }
        }

        return [
            'cout' => $cout,
            'net' => $encaisse - $cout,
            'complet' => $manquants === 0,
            'manquants' => $manquants,
        ];
    }

    protected function getHeaderActions(): array
    {
        $group = auth('group')->user()->group;
        $periode = \App\Support\Period\PeriodFactory::default()->label();

        return [
            $this->actionsRapport(
                'consolidation',
                'Consolidation financière',
                fn () => new ConsolidationFinanciereReport(
                    $this->getFinancials(),
                    (string) $group->name,
                    $periode,
                ),
                'heroicon-o-banknotes',
            ),

            $this->actionsRapport(
                'masse_salariale',
                'Masse salariale',
                fn () => new MasseSalarialeReport(
                    $this->getPayroll(),
                    (string) $group->name,
                    $periode,
                ),
                'heroicon-o-users',
            ),
        ];
    }
}
