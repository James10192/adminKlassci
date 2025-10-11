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

            // Compter le personnel (enseignant, coordinateur, secretaire)
            // Note: users et staff comptent la même chose (personnel avec compte)
            $staffCount = DB::connection($connectionName)
                ->table('users')
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->whereIn('roles.name', ['enseignant', 'coordinateur', 'secretaire'])
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->distinct()
                ->count('users.id');

            // Compter les étudiants ayant un compte utilisateur (user_id IS NOT NULL)
            // Important: On compte les étudiants avec accès plateforme, pas les inscriptions
            try {
                $studentsCount = DB::connection($connectionName)
                    ->table('esbtp_etudiants')
                    ->whereNotNull('user_id')
                    ->whereNull('deleted_at')
                    ->count();
            } catch (\Exception $e) {
                Log::warning("Table esbtp_etudiants not found for tenant {$tenant->code}");
                $studentsCount = 0;
            }

            // Compter les inscriptions actives pour l'année universitaire courante
            // Important: Ceci est distinct du nombre d'étudiants - une école peut avoir 1000 étudiants
            // dans la BDD mais seulement 300 inscriptions actives cette année
            try {
                // Trouver l'année universitaire courante
                $currentYear = DB::connection($connectionName)
                    ->table('esbtp_annee_universitaires')
                    ->where('is_current', 1)
                    ->first();

                if ($currentYear) {
                    $inscriptionsCount = DB::connection($connectionName)
                        ->table('esbtp_inscriptions')
                        ->where('annee_universitaire_id', $currentYear->id)
                        ->where('status', 'active')
                        ->count();
                } else {
                    Log::warning("No current academic year found for tenant {$tenant->code}");
                    $inscriptionsCount = 0;
                }
            } catch (\Exception $e) {
                Log::warning("Could not count inscriptions for tenant {$tenant->code}: " . $e->getMessage());
                $inscriptionsCount = 0;
            }

            // Calculer le stockage (approximatif - sans accès SSH)
            // On va utiliser la taille des uploads dans la DB comme approximation
            $storageMb = 0; // TODO: Implémenter avec SSH ou API cPanel

            Log::info("Stats retrieved for tenant {$tenant->code}", [
                'staff' => $staffCount,
                'students_with_account' => $studentsCount,
                'inscriptions_current_year' => $inscriptionsCount,
                'storage_mb' => $storageMb,
            ]);

            return [
                'current_users' => $staffCount, // users = staff (personnel avec compte)
                'current_staff' => $staffCount, // redondant mais pour clarté
                'current_students' => $studentsCount, // étudiants avec user_id (compte plateforme)
                'current_inscriptions_per_year' => $inscriptionsCount, // inscriptions année courante
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
