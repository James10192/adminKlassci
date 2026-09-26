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

    public array $tenants = [];

    protected ?string $tenantConnectionName = null;

    protected ?Tenant $resolvedTenant = null;

    /** Ce qui a empêché de lire l'école, en mots de l'équipe ; null si tout va bien. */
    public ?string $erreurTenant = null;

    public function mountTenantConfigTrait(): void
    {
        $this->tenants = $this->getActiveTenants();
    }

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

        return $this->resolvedTenant ??= Tenant::find($this->selectedTenantId);
    }

    protected function requireTenant(): bool
    {
        if (! $this->selectedTenantId) {
            Notification::make()->title('Sélectionnez un tenant')->warning()->send();
            return false;
        }
        return true;
    }

    protected function connectToTenant(): ?string
    {
        $tenant = $this->getSelectedTenant();
        if (! $tenant) {
            return null;
        }

        // Chaque lecture ou écriture rouvre la connexion : c'est le moment
        // d'oublier l'échec précédent, sinon le bandeau survivait aux succès.
        $this->erreurTenant = null;

        try {
            $manager = app(TenantConnectionManager::class);
            $this->tenantConnectionName = $manager->createConnection($tenant);
            return $this->tenantConnectionName;
        } catch (\Exception $e) {
            $this->signalerEchecTenant($e, 'connexion');
            return null;
        }
    }

    /**
     * Journalise l'erreur complète et n'en montre qu'une phrase utile.
     *
     * Le message brut de MySQL s'affichait tel quel : nom d'utilisateur, nom
     * de base et requête SQL dans un toast, et rien sur ce qu'il fallait faire.
     */
    protected function signalerEchecTenant(\Throwable $e, string $operation, bool $lecture = true): void
    {
        $tenant = $this->getSelectedTenant();
        $nom = $tenant?->name ?? 'cet établissement';

        Log::error("TenantConfig : échec de {$operation}", [
            'tenant' => $tenant?->code,
            'page' => static::class,
            'error' => $e->getMessage(),
        ]);

        $message = str_contains($e->getMessage(), 'Access denied')
            ? "La base de {$nom} refuse les identifiants enregistrés. Corrigez-les dans la fiche de l'établissement (onglet Configuration technique)."
            : "Échec de l'opération « {$operation} » pour {$nom}. Le détail est dans le journal de l'admin.";

        // Seule une lecture ratée laisse la page vide : c'est elle qui mérite
        // un bandeau. Une écriture ratée laisse la page lisible, un toast suffit.
        if ($lecture) {
            $this->erreurTenant = $message;
        }

        Notification::make()
            ->title($lecture ? 'Établissement inaccessible' : 'Modification non enregistrée')
            ->body($message)
            ->danger()
            ->send();
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

    protected function resetTenantState(): void
    {
        $this->closeTenantConnection();
        $this->resolvedTenant = null;
        $this->erreurTenant = null;
    }

    protected function closeTenantConnection(): void
    {
        if ($this->tenantConnectionName) {
            app(TenantConnectionManager::class)->closeConnection($this->tenantConnectionName);
            $this->tenantConnectionName = null;
        }
    }
}
