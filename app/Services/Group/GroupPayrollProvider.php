<?php

namespace App\Services\Group;

use App\Contracts\Group\GroupPayrollProviderInterface;
use App\Models\Group;
use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use App\Support\EtatMesure;
use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupPayrollProvider implements GroupPayrollProviderInterface
{
    public function __construct(
        private readonly TenantConnectionManager $connectionManager,
        private readonly TenantAggregator $aggregator,
        private readonly PayrollStateResolver $stateResolver,
    ) {
    }

    public function computeGroupPayroll(Group $group, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();

        $totaux = [
            'masse_brute' => 0.0,
            'masse_nette' => 0.0,
            'retenues' => 0.0,
            'masse_versee' => 0.0,
            'masse_engagee' => 0.0,
            'masse_brouillon' => 0.0,
            'bulletins' => 0,
            'bulletins_brouillon' => 0,
            'enseignants' => 0,
            'establishments' => [],
        ];

        $parEtablissement = $this->aggregator->aggregate(
            $group,
            self::class,
            'computeTenantPayroll',
            'Payroll',
            $period,
        );

        foreach ($group->activeTenants as $tenant) {
            $paie = $parEtablissement[$tenant->code] ?? $this->emptyPayroll($tenant);

            $totaux['masse_brute'] += $paie['masse_brute'];
            $totaux['masse_nette'] += $paie['masse_nette'];
            $totaux['retenues'] += $paie['retenues'];
            $totaux['masse_versee'] += $paie['masse_versee'];
            $totaux['masse_engagee'] += $paie['masse_engagee'];
            $totaux['masse_brouillon'] += $paie['masse_brouillon'];
            $totaux['bulletins'] += $paie['bulletins'];
            $totaux['bulletins_brouillon'] += $paie['bulletins_brouillon'];
            $totaux['enseignants'] += $paie['enseignants'];

            $totaux['establishments'][$tenant->code] = $paie;
        }

        $totaux['establishment_count'] = count($totaux['establishments']);

        return $totaux;
    }

    public function computeTenantPayroll(Tenant $tenant, ?PeriodInterface $period = null): array
    {
        $period ??= PeriodFactory::default();
        $conn = $this->connectionManager->createConnection($tenant);

        try {
            // Bornes AAAAMM plutôt qu'un intervalle de dates : la table ne porte
            // pas de date de période, seulement `annee` et `mois`. La comparaison
            // entière évite un STR_TO_DATE par ligne.
            $debut = (int) $period->startDate()->format('Ym');
            $fin = (int) $period->endDate()->format('Ym');

            // Un agrégat par couple de statuts — au plus une poignée de lignes.
            // On classe ensuite en PHP : la règle de lecture des deux colonnes
            // vit dans PayrollStateResolver, testée, plutôt que dupliquée en SQL.
            $groupes = DB::connection($conn)
                ->table('esbtp_salaires')
                ->whereNull('deleted_at')
                ->whereRaw('(annee * 100 + mois) BETWEEN ? AND ?', [$debut, $fin])
                ->groupBy('workflow_status', 'statut')
                ->selectRaw('
                    workflow_status,
                    statut,
                    COUNT(*) as bulletins,
                    SUM(COALESCE(salaire_base, 0) + COALESCE(primes, 0)) as brut,
                    SUM(COALESCE(net_a_payer, 0)) as net,
                    SUM(COALESCE(retenues, 0)) as retenues
                ')
                ->get();

            $paie = $this->emptyPayroll($tenant);
            $paie['error'] = false;
            $paie['etat'] = EtatMesure::MESURE;
            $paie['motif'] = null;

            foreach ($groupes as $ligne) {
                $etat = $this->stateResolver->resolve($ligne->workflow_status, $ligne->statut);

                if ($etat === PayrollStateResolver::ANNULE) {
                    continue;
                }

                $brut = (float) $ligne->brut;
                $net = (float) $ligne->net;

                if ($etat === PayrollStateResolver::BROUILLON) {
                    // Compté à part : préparé, pas encore un engagement.
                    $paie['masse_brouillon'] += $net;
                    $paie['bulletins_brouillon'] += (int) $ligne->bulletins;
                    continue;
                }

                $paie['masse_brute'] += $brut;
                $paie['masse_nette'] += $net;
                $paie['retenues'] += (float) $ligne->retenues;
                $paie['bulletins'] += (int) $ligne->bulletins;

                if ($etat === PayrollStateResolver::PAYE) {
                    $paie['masse_versee'] += $net;
                } else {
                    $paie['masse_engagee'] += $net;
                }
            }

            $paie['enseignants'] = $this->countEnseignants($conn, $debut, $fin);

            // La base a repondu et ne tient aucun bulletin sur la periode : ce
            // n'est pas une panne, c'est une ecole qui ne fait pas sa paie ici.
            // Les deux affichaient le meme zero — l'une merite une alerte,
            // l'autre non.
            if ($paie['bulletins'] === 0 && $paie['bulletins_brouillon'] === 0 && $paie['enseignants'] === 0) {
                $paie['etat'] = EtatMesure::NON_APPLICABLE;
                $paie['motif'] = EtatMesure::MOTIF_SANS_MODULE;
            }

            foreach (['masse_brute', 'masse_nette', 'retenues', 'masse_versee', 'masse_engagee', 'masse_brouillon'] as $cle) {
                $paie[$cle] = round($paie[$cle], 2);
            }

            return $paie;
        } catch (\Exception $e) {
            Log::error("[group-payroll] computeTenantPayroll failed for {$tenant->code}: {$e->getMessage()}");

            return $this->emptyPayroll($tenant);
        } finally {
            $this->connectionManager->closeConnection($conn);
        }
    }

    /**
     * Enseignants distincts ayant au moins un bulletin sur la période.
     *
     * `teacher_id` n'existe que depuis juin 2026 et reste nullable ; on retombe
     * sur `user_id`, présent depuis l'origine, pour ne perdre personne.
     */
    private function countEnseignants(string $conn, int $debut, int $fin): int
    {
        $ligne = DB::connection($conn)
            ->table('esbtp_salaires')
            ->whereNull('deleted_at')
            ->whereRaw('(annee * 100 + mois) BETWEEN ? AND ?', [$debut, $fin])
            ->selectRaw('COUNT(DISTINCT COALESCE(teacher_id, user_id)) as total')
            ->first();

        return (int) ($ligne->total ?? 0);
    }

    /**
     * Structure stable, à zéro.
     *
     * `error` distinguait déjà « aucune paie sur la période » de
     * « établissement injoignable ». Il manquait le troisième cas, qui
     * ressemblait aux deux autres : une école qui ne fait tout simplement pas
     * sa paie dans KLASSCI. `etat` le nomme — `NON_APPLICABLE` — parce qu'une
     * école sans module ne doit pas déclencher les alertes d'une école en
     * panne, et qu'un tiret ne se justifie pas de la même façon.
     */
    public function emptyPayroll(Tenant $tenant): array
    {
        return [
            'tenant_id' => $tenant->id,
            'tenant_code' => $tenant->code,
            'tenant_name' => $tenant->name,
            'masse_brute' => 0.0,
            'masse_nette' => 0.0,
            'retenues' => 0.0,
            'masse_versee' => 0.0,
            'masse_engagee' => 0.0,
            'masse_brouillon' => 0.0,
            'bulletins' => 0,
            'bulletins_brouillon' => 0,
            'enseignants' => 0,
            'error' => true,
            'etat' => EtatMesure::NON_MESURE,
            'motif' => EtatMesure::MOTIF_INJOIGNABLE,
        ];
    }
}
