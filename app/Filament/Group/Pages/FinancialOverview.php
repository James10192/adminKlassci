<?php

namespace App\Filament\Group\Pages;

use App\Contracts\Group\GroupPayrollProviderInterface;
use App\Domain\Exports\Reports\ConsolidationFinanciereReport;
use App\Domain\Exports\Reports\MasseSalarialeReport;
use App\Filament\Group\Concerns\HasCustomHero;
use App\Filament\Group\Concerns\HasReportActions;
use App\Services\TenantAggregationService;
use App\Support\EtatMesure;
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
        $mesures = 0;
        $manquants = [];

        foreach ($financials as $code => $data) {
            // On somme les repondants et on nomme le denominateur. Additionner
            // le zero d'une base injoignable ne change pas le total, mais un
            // total qui ne dit pas sur combien d'ecoles il porte laisse croire
            // qu'il porte sur toutes.
            if (! EtatMesure::estMesure($data['etat'] ?? EtatMesure::MESURE)) {
                $manquants[$code] = [
                    'nom' => $data['tenant_name'] ?? $code,
                    'motif' => $data['motif'] ?? EtatMesure::MOTIF_INJOIGNABLE,
                ];

                continue;
            }

            $mesures++;
            $totalExpected += $data['revenue_expected'];
            $totalCollected += $data['revenue_collected'];
        }

        return [
            'expected' => $totalExpected,
            'collected' => $totalCollected,
            'outstanding' => max(0, $totalExpected - $totalCollected),
            'surplus' => max(0, $totalCollected - $totalExpected),
            'rate' => $totalExpected > 0 ? min(100, round(($totalCollected / $totalExpected) * 100, 1)) : 0,
            'perimetre' => [
                'total' => count($financials),
                'repondu' => $mesures,
                'manquants' => $manquants,
                'complet' => $manquants === [],
                'etat' => $mesures === 0 && $financials !== []
                    ? EtatMesure::NON_MESURE
                    : EtatMesure::MESURE,
            ],
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
     * `cout_mesure` et `net_mesure` disent s'il y a un chiffre à afficher.
     * Quand AUCUNE école n'a répondu, la masse brute vaut 0 et le net vaut
     * `0 - 0 = 0` : la tuile annonçait « Masse salariale 0 · 0 enseignant »
     * et « Reste après paie 0 » à un fondateur dont les écoles emploient des
     * dizaines d'enseignants. Une soustraction entre deux inconnues n'est pas
     * un résultat nul, c'est une absence de résultat.
     *
     * @return array{cout: float, net: float, complet: bool, manquants: int, cout_mesure: bool, net_mesure: bool}
     */
    public function getResultat(): array
    {
        $paie = $this->getPayroll();
        $totaux = $this->getTotals();
        $encaisse = (float) ($totaux['collected'] ?? 0);
        $cout = (float) $paie['masse_brute'];

        // Une ecole injoignable manque a l'appel ; une ecole qui ne fait
        // simplement pas sa paie dans KLASSCI n'a rien a manquer. Les deux
        // portaient `error` et se comptaient pareil.
        $manquants = 0;
        $mesures = 0;
        foreach ($paie['establishments'] as $etablissement) {
            $etat = $etablissement['etat'] ?? EtatMesure::MESURE;

            if ($etat === EtatMesure::NON_MESURE) {
                $manquants++;
            } elseif (EtatMesure::aUneValeur($etat)) {
                $mesures++;
            }
        }

        // Le net croise la paie et l'encaissé : les deux doivent être mesurés,
        // sinon on soustrait un coût connu d'une recette inconnue (ou l'inverse)
        // et le chiffre obtenu ne veut rien dire.
        $encaisseMesure = EtatMesure::estMesure($totaux['perimetre']['etat'] ?? EtatMesure::MESURE);

        return [
            'cout' => $cout,
            'net' => $encaisse - $cout,
            'complet' => $manquants === 0,
            'manquants' => $manquants,
            'cout_mesure' => $mesures > 0,
            'net_mesure' => $mesures > 0 && $encaisseMesure,
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
