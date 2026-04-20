<?php

namespace App\Services\Group;

use App\Services\TenantConnectionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Preloads inscriptions + categories + subscriptions + configurations for a tenant.
 * Memoized by (tenant, année) for the request lifecycle.
 *
 * Must be bound as scoped() so memoization resets between requests and doesn't leak in Octane.
 */
class TenantBillingContext
{
    /** @var array<string, array> */
    private array $cache = [];

    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    public function __construct(private readonly TenantConnectionManager $connectionManager)
    {
    }

    /**
     * @return array{inscriptions:\Illuminate\Support\Collection,categories:\Illuminate\Support\Collection,subscriptions:\Illuminate\Support\Collection,configurations:\Illuminate\Support\Collection}
     */
    public function load(string $conn, int $tenantId, int $anneeId): array
    {
        $key = "{$tenantId}_{$anneeId}";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $inscriptions = DB::connection($conn)
            ->table('esbtp_inscriptions')
            ->whereIn('status', ['active', 'en_attente', 'validée'])
            ->where('annee_universitaire_id', $anneeId)
            ->get(['id', 'filiere_id', 'niveau_id', 'affectation_status', 'status', 'workflow_step', 'etudiant_id', 'created_at']);

        $categories = DB::connection($conn)
            ->table('esbtp_frais_categories')
            ->where('is_active', true)
            ->get(['id', 'is_mandatory', 'default_amount']);

        $subscriptions = DB::connection($conn)
            ->table('esbtp_frais_subscriptions')
            ->where('is_active', true)
            ->whereIn('inscription_id', $inscriptions->pluck('id'))
            ->get(['inscription_id', 'frais_category_id', 'amount'])
            ->groupBy('inscription_id');

        $configurations = DB::connection($conn)
            ->table('esbtp_frais_configurations')
            ->where('is_active', true)
            ->whereIn('frais_category_id', $categories->pluck('id'))
            ->get(['frais_category_id', 'filiere_id', 'niveau_id', 'amount', 'amount_affecte', 'amount_reaffecte', 'amount_non_affecte'])
            ->groupBy(fn ($c) => $c->frais_category_id . '_' . $c->filiere_id . '_' . $c->niveau_id);

        return $this->cache[$key] = compact('inscriptions', 'categories', 'subscriptions', 'configurations');
    }

    /**
     * Resolves the amount due for a category on an inscription.
     * Replicates ESBTPComptabiliteController::calculerTotalDu() logic tenant-side.
     */
    public function resolveCategoryAmount($inscription, $category, $inscSubs, $configurations): float
    {
        $sub = $inscSubs->firstWhere('frais_category_id', $category->id);

        if (! $category->is_mandatory) {
            return $sub ? (float) $sub->amount : 0.0;
        }

        if ($sub) {
            return (float) $sub->amount;
        }

        $key = $category->id . '_' . $inscription->filiere_id . '_' . $inscription->niveau_id;
        $config = $configurations->get($key, collect())->first();

        if (! $config) {
            return (float) ($category->default_amount ?? 0);
        }

        // Column name derived from affectation_status: 'affecté' → amount_affecte, 'non_affecté' → amount_non_affecte.
        $status = $inscription->affectation_status ?? 'affecté';
        $field = 'amount_' . str_replace('é', 'e', $status);

        return (float) ($config->{$field} ?? $config->amount ?? $category->default_amount ?? 0);
    }

    /**
     * Sum of "total due" across all inscriptions × categories for a tenant-year.
     */
    public function computeRevenueExpected(string $conn, int $tenantId, int $anneeId): float
    {
        try {
            $ctx = $this->load($conn, $tenantId, $anneeId);

            if ($ctx['inscriptions']->isEmpty()) {
                return 0;
            }

            $totalDue = 0;
            foreach ($ctx['inscriptions'] as $inscription) {
                $inscSubs = $ctx['subscriptions']->get($inscription->id, collect());
                foreach ($ctx['categories'] as $category) {
                    $totalDue += $this->resolveCategoryAmount($inscription, $category, $inscSubs, $ctx['configurations']);
                }
            }

            return $totalDue;
        } catch (\Exception $e) {
            Log::warning("[group-refactor] computeRevenueExpected failed: {$e->getMessage()}");
            return 0;
        }
    }

    public function hasTable(string $conn, int $tenantId, string $table): bool
    {
        $key = "{$tenantId}_{$table}";
        if (! isset($this->tableExistsCache[$key])) {
            $this->tableExistsCache[$key] = DB::connection($conn)->getSchemaBuilder()->hasTable($table);
        }
        return $this->tableExistsCache[$key];
    }

    /** For testing — resets memoization. */
    public function reset(): void
    {
        $this->cache = [];
        $this->tableExistsCache = [];
    }
}
