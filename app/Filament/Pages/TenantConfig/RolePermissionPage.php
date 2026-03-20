<?php

namespace App\Filament\Pages\TenantConfig;

use App\Filament\Traits\TenantConfigTrait;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class RolePermissionPage extends Page
{
    use TenantConfigTrait;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Rôles & Permissions';
    protected static ?string $navigationGroup = 'Configuration Tenants';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.tenant-config.role-permission';
    protected static ?string $title = 'Gestion des Rôles & Permissions';

    public array $tenants = [];
    public array $roles = [];
    public array $permissions = [];
    public array $groupedPermissions = [];
    public ?int $selectedRoleId = null;
    public string $selectedRoleName = '';
    public array $rolePermissionIds = [];

    public function mount(): void
    {
        $this->tenants = $this->getActiveTenants();
    }

    public function updatedSelectedTenantId(): void
    {
        $this->roles = [];
        $this->permissions = [];
        $this->groupedPermissions = [];
        $this->selectedRoleId = null;
        $this->selectedRoleName = '';
        $this->rolePermissionIds = [];
        $this->loadRolesAndPermissions();
    }

    public function loadRolesAndPermissions(): void
    {
        $db = $this->tenantDb();
        if (! $db) {
            return;
        }

        try {
            // Charger les rôles
            $this->roles = $db->table('roles')
                ->orderBy('name')
                ->get(['id', 'name', 'guard_name'])
                ->map(fn ($r) => (array) $r)
                ->toArray();

            // Charger les permissions
            $perms = $db->table('permissions')
                ->orderBy('name')
                ->get(['id', 'name', 'guard_name'])
                ->map(fn ($p) => (array) $p)
                ->toArray();

            $this->permissions = $perms;

            // Grouper par catégorie (premier segment avant le point ou underscore)
            $grouped = [];
            foreach ($perms as $perm) {
                $name = $perm['name'];
                // Catégoriser par premier segment
                if (str_contains($name, '.')) {
                    $group = explode('.', $name)[0];
                } elseif (str_contains($name, '_')) {
                    $parts = explode('_', $name);
                    $group = $parts[count($parts) - 1]; // last word for "view_own_grades" → "grades"
                } else {
                    $group = 'Autres';
                }
                $group = ucfirst($group);
                $grouped[$group][] = $perm;
            }
            ksort($grouped);
            $this->groupedPermissions = $grouped;

        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body('Tables Spatie non trouvées: ' . $e->getMessage())
                ->danger()
                ->send();
        }

        $this->closeTenantConnection();
    }

    public function selectRole(int $roleId): void
    {
        $this->selectedRoleId = $roleId;
        $role = collect($this->roles)->firstWhere('id', $roleId);
        $this->selectedRoleName = $role['name'] ?? '';

        $db = $this->tenantDb();
        if (! $db) {
            return;
        }

        // Charger les permissions du rôle
        $this->rolePermissionIds = $db->table('role_has_permissions')
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $this->closeTenantConnection();
    }

    public function togglePermission(int $permissionId): void
    {
        if (in_array($permissionId, $this->rolePermissionIds)) {
            $this->rolePermissionIds = array_values(array_diff($this->rolePermissionIds, [$permissionId]));
        } else {
            $this->rolePermissionIds[] = $permissionId;
        }
    }

    public function savePermissions(): void
    {
        if (! $this->selectedRoleId || ! $this->selectedTenantId) {
            Notification::make()->title('Sélectionnez un rôle')->warning()->send();
            return;
        }

        $db = $this->tenantDb();
        if (! $db) {
            return;
        }

        $tenant = $this->getSelectedTenant();

        try {
            $db->beginTransaction();

            // Supprimer les anciennes permissions du rôle
            $db->table('role_has_permissions')
                ->where('role_id', $this->selectedRoleId)
                ->delete();

            // Insérer les nouvelles
            $inserts = [];
            foreach ($this->rolePermissionIds as $permId) {
                $inserts[] = [
                    'permission_id' => $permId,
                    'role_id' => $this->selectedRoleId,
                ];
            }

            if (! empty($inserts)) {
                $db->table('role_has_permissions')->insert($inserts);
            }

            $db->commit();

            // Clear Spatie permission cache
            // On ne peut pas appeler artisan sur le tenant distant, mais on peut vider le cache table
            try {
                $db->table('cache')->where('key', 'like', '%spatie%permission%')->delete();
            } catch (\Exception $e) {
                // Cache table might not exist, ignore
            }

            $this->closeTenantConnection();

            $this->logConfigChange(
                'role_permissions_updated',
                "Permissions du rôle '{$this->selectedRoleName}' mises à jour sur {$tenant->name} (" . count($this->rolePermissionIds) . " permissions)",
                ['role' => $this->selectedRoleName, 'permission_count' => count($this->rolePermissionIds)]
            );

            Notification::make()
                ->title('Permissions sauvegardées')
                ->body(count($this->rolePermissionIds) . " permissions assignées au rôle '{$this->selectedRoleName}' sur {$tenant->name}.")
                ->success()
                ->send();

        } catch (\Exception $e) {
            $db->rollBack();
            $this->closeTenantConnection();

            Notification::make()
                ->title('Erreur')
                ->body('Erreur lors de la sauvegarde: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
