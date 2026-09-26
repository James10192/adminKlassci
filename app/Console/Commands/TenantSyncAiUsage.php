<?php

namespace App\Console\Commands;

use App\Domain\AssistantIa\SynchronisationConsommation;
use App\Domain\AssistantIa\SynchronisationRetours;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TenantSyncAiUsage extends Command
{
    protected $signature = 'tenant:sync-ai-usage
                            {tenant? : Code de l\'école (toutes les écoles actives si omis)}
                            {--all : Inclure les écoles suspendues}';

    protected $description = 'Rapatrie la consommation d\'IA (assistant Nanan) et les avis 👍 / 👎 de chaque école vers le master';

    public function handle(SynchronisationConsommation $synchro, SynchronisationRetours $retours): int
    {
        $code = $this->argument('tenant');
        $tenants = $code
            ? Tenant::where('code', $code)->get()
            : ($this->option('all') ? Tenant::all() : Tenant::active()->get());

        if ($tenants->isEmpty()) {
            $this->error($code ? "Tenant '{$code}' introuvable." : 'Aucune école à synchroniser.');

            return $code ? self::FAILURE : self::SUCCESS;
        }

        $echecs = 0;
        foreach ($tenants as $tenant) {
            try {
                $n = $synchro->synchroniser($tenant);
                $this->line($n < 0
                    ? "{$tenant->code} : pas encore de table de consommation (école non déployée)"
                    : "{$tenant->code} : {$n} ligne(s) copiée(s)");
                $a = $retours->synchroniser($tenant);
                if ($a > 0) {
                    $this->line("{$tenant->code} : {$a} avis relevé(s)");
                }
            } catch (\Throwable $e) {
                $echecs++;
                // Une école injoignable ne doit pas empêcher les autres d'être relevées.
                Log::error('tenant:sync-ai-usage en échec', ['tenant' => $tenant->code, 'erreur' => $e->getMessage()]);
                $this->error("{$tenant->code} : échec ({$e->getMessage()})");
            }
        }

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
