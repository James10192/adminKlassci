<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantConnectionManager
{
    /**
     * Créer une connexion dynamique pour un tenant
     *
     * @param Tenant $tenant
     * @return string Nom de la connexion
     * @throws \Exception
     */
    public function createConnection(Tenant $tenant): string
    {
        $connectionName = "tenant_{$tenant->code}";

        // Récupérer les credentials depuis le tenant
        $credentials = $tenant->database_credentials;

        if (!$credentials || !isset($credentials['host'], $credentials['username'], $credentials['password'])) {
            throw new \Exception("Invalid database credentials for tenant {$tenant->code}");
        }

        // Configurer la connexion dynamiquement
        Config::set("database.connections.{$connectionName}", [
            'driver' => 'mysql',
            'host' => $credentials['host'],
            'port' => $credentials['port'] ?? 3306,
            'database' => $tenant->database_name,  // Utiliser database_name du tenant
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        Log::info("Created dynamic connection for tenant {$tenant->code}", [
            'connection' => $connectionName,
            'host' => $credentials['host'],
            'database' => $tenant->database_name,
        ]);

        return $connectionName;
    }

    /**
     * Tester la connexion à un tenant
     *
     * @param Tenant $tenant
     * @return bool
     */
    public function testConnection(Tenant $tenant): bool
    {
        try {
            $connectionName = $this->createConnection($tenant);

            // Test de connexion simple
            DB::connection($connectionName)->getPdo();

            Log::info("Connection test successful for tenant {$tenant->code}");

            return true;
        } catch (\Exception $e) {
            Log::error("Connection test failed for tenant {$tenant->code}", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Récupérer les statistiques réelles d'un tenant
     *
     * @param Tenant $tenant
     * @return array
     */
    public function getTenantStats(Tenant $tenant): array
    {
        try {
            $connectionName = $this->createConnection($tenant);

            // Compter les utilisateurs (superAdmin, coordinateur, secretaire)
            $usersCount = DB::connection($connectionName)
                ->table('users')
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->whereIn('roles.name', ['superAdmin', 'coordinateur', 'secretaire'])
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->distinct()
                ->count('users.id');

            // Compter le personnel (enseignant, coordinateur, secretaire)
            $staffCount = DB::connection($connectionName)
                ->table('users')
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->whereIn('roles.name', ['enseignant', 'coordinateur', 'secretaire'])
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->distinct()
                ->count('users.id');

            // Compter les étudiants (avec gestion d'erreur si table n'existe pas)
            try {
                $studentsCount = DB::connection($connectionName)
                    ->table('esbtp_etudiants')
                    ->whereNull('deleted_at')
                    ->count();
            } catch (\Exception $e) {
                Log::warning("Table esbtp_etudiants not found for tenant {$tenant->code}");
                $studentsCount = 0;
            }

            // Calculer le stockage (approximatif - sans accès SSH)
            // On va utiliser la taille des uploads dans la DB comme approximation
            $storageMb = 0; // TODO: Implémenter avec SSH ou API cPanel

            Log::info("Stats retrieved for tenant {$tenant->code}", [
                'users' => $usersCount,
                'staff' => $staffCount,
                'students' => $studentsCount,
                'storage_mb' => $storageMb,
            ]);

            return [
                'current_users' => $usersCount,
                'current_staff' => $staffCount,
                'current_students' => $studentsCount,
                'current_storage_mb' => $storageMb,
            ];

        } catch (\Exception $e) {
            Log::error("Failed to retrieve stats for tenant {$tenant->code}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Fermer la connexion d'un tenant
     *
     * @param string $connectionName
     * @return void
     */
    public function closeConnection(string $connectionName): void
    {
        DB::purge($connectionName);

        Log::info("Closed connection {$connectionName}");
    }

    /**
     * Mettre à jour les stats d'un tenant dans klassci_master
     *
     * @param Tenant $tenant
     * @return Tenant
     */
    public function updateTenantStats(Tenant $tenant): Tenant
    {
        $stats = $this->getTenantStats($tenant);

        $tenant->update($stats);

        Log::info("Updated stats for tenant {$tenant->code}", $stats);

        return $tenant->fresh();
    }
}
