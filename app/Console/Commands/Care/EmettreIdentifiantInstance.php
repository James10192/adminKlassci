<?php

namespace App\Console\Commands\Care;

use App\Domain\Care\Acces\Models\TenantApiCredential;
use App\Models\Tenant;
use App\Models\TenantActivityLog;
use Illuminate\Console\Command;

/**
 * Emet l'identifiant qu'une instance presentera a KLASSCI Care.
 *
 *   php artisan care:identifiant presentation
 *   php artisan care:identifiant presentation --expire=90
 *   php artisan care:identifiant presentation --revoquer=abc123def456
 *
 * Le jeton ne s'affiche qu'ici, une fois. Il se pose dans le .env de
 * l'instance sous MASTER_SUPPORT_TOKEN.
 */
class EmettreIdentifiantInstance extends Command
{
    protected $signature = 'care:identifiant
        {tenant : Code de l\'instance}
        {--portees=support:create,support:read : Portées, séparées par des virgules}
        {--libelle= : Libellé libre (ex. « rotation septembre »)}
        {--expire= : Durée de validité en jours (sans option : n\'expire pas)}
        {--revoquer= : key_id d\'un identifiant à révoquer au lieu d\'en émettre un}';

    protected $description = 'Émettre ou révoquer l\'identifiant KLASSCI Care d\'une instance';

    public function handle(): int
    {
        $tenant = Tenant::where('code', $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('Instance introuvable : '.$this->argument('tenant'));

            return self::FAILURE;
        }

        if ($keyId = $this->option('revoquer')) {
            return $this->revoquer($tenant, $keyId);
        }

        $portees = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('portees')))));

        $jours = $this->option('expire');
        if ($jours !== null && (! ctype_digit((string) $jours) || (int) $jours < 1)) {
            $this->error('--expire attend un nombre de jours positif.');

            return self::FAILURE;
        }
        $expireLe = $jours !== null ? now()->addDays((int) $jours) : null;

        try {
            [$credential, $jeton] = TenantApiCredential::emettre($tenant, $portees, $this->option('libelle'), $expireLe);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        TenantActivityLog::log($tenant->id, 'care_credential_issued',
            "Identifiant KLASSCI Care émis ({$credential->key_id})", null, ['scopes' => $credential->scopes, 'expires_at' => $expireLe?->toIso8601String()]);

        $this->info("Identifiant émis pour {$tenant->code} (key_id {$credential->key_id})"
            .($expireLe ? ', valable jusqu\'au '.$expireLe->format('d/m/Y').'.' : ', sans expiration.'));
        $this->line('À poser dans le .env de l\'instance — il ne sera plus affiché :');
        $this->newLine();
        $this->line("MASTER_SUPPORT_TOKEN={$jeton}");
        $this->newLine();

        return self::SUCCESS;
    }

    private function revoquer(Tenant $tenant, string $keyId): int
    {
        $credential = TenantApiCredential::where('tenant_id', $tenant->id)->where('key_id', $keyId)->first();
        if (! $credential) {
            $this->error("Aucun identifiant {$keyId} pour {$tenant->code}.");

            return self::FAILURE;
        }

        $credential->revoquer();
        TenantActivityLog::log($tenant->id, 'care_credential_revoked', "Identifiant KLASSCI Care révoqué ({$keyId})");
        $this->info("Identifiant {$keyId} révoqué.");

        return self::SUCCESS;
    }
}
