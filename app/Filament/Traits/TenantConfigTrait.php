<?php

namespace App\Filament\Traits;

use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Services\TenantConnectionManager;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait TenantConfigTrait
{
    public ?int $selectedTenantId = null;

    protected ?string $tenantConnectionName = null;

    public function getActiveTenants(): array
    {
        return Tenant::active()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn ($t) => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name])
            ->toArray();
    }

    public function getSelectedTenant(): ?Tenant
    {
        if (! $this->selectedTenantId) {
            return null;
        }

        return Tenant::find($this->selectedTenantId);
    }

    protected function connectToTenant(): ?string
    {
        $tenant = $this->getSelectedTenant();
        if (! $tenant) {
            return null;
        }

        try {
            $manager = app(TenantConnectionManager::class);
            $this->tenantConnectionName = $manager->createConnection($tenant);
            return $this->tenantConnectionName;
        } catch (\Exception $e) {
            Log::error("Failed to connect to tenant {$tenant->code}", ['error' => $e->getMessage()]);
            Notification::make()
                ->title('Erreur de connexion')
                ->body("Impossible de se connecter à la base de {$tenant->name}: {$e->getMessage()}")
                ->danger()
                ->send();
            return null;
        }
    }

    protected function tenantDb(): ?\Illuminate\Database\ConnectionInterface
    {
        if (! $this->tenantConnectionName) {
            $this->connectToTenant();
        }

        if (! $this->tenantConnectionName) {
            return null;
        }

        return DB::connection($this->tenantConnectionName);
    }

    protected function logConfigChange(string $action, string $description, array $metadata = []): void
    {
        $tenant = $this->getSelectedTenant();
        if (! $tenant) {
            return;
        }

        TenantActivityLog::log(
            tenantId: $tenant->id,
            action: $action,
            description: $description,
            performedByUserId: auth()->id(),
            metadata: $metadata,
        );
    }

    protected function closeTenantConnection(): void
    {
        if ($this->tenantConnectionName) {
            DB::purge($this->tenantConnectionName);
            $this->tenantConnectionName = null;
        }
    }
}
