<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantHealthCheck as TenantHealthCheckModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TenantHealthCheck extends Command
{
    protected $signature = 'tenant:health-check
                            {tenant? : Code du tenant (si omis, vérifie tous les tenants actifs)}
                            {--check= : Type de vérification spécifique (http_status, database_connection, disk_space, ssl_certificate, application_errors, queue_workers)}
                            {--all : Forcer la vérification de tous les tenants (actifs + suspendus)}';

    protected $description = 'Vérifier la santé des tenants (HTTP, DB, stockage, SSL, erreurs, queues)';

    private const CHECKS = [
        'http_status',
        'database_connection',
        'disk_space',
        'ssl_certificate',
        'application_errors',
        'queue_workers',
    ];

    public function handle()
    {
        $tenantCode = $this->argument('tenant');
        $specificCheck = $this->option('check');
        $checkAll = $this->option('all');

        // Validation du type de check spécifique
        if ($specificCheck && !in_array($specificCheck, self::CHECKS)) {
            $this->error("❌ Type de vérification invalide. Options: " . implode(', ', self::CHECKS));
            return 1;
        }

        if ($tenantCode) {
            // Vérifier un seul tenant
            $tenant = Tenant::where('code', $tenantCode)->first();

            if (!$tenant) {
                $this->error("❌ Tenant '{$tenantCode}' introuvable.");
                return 1;
            }

            $this->performHealthChecks($tenant, $specificCheck);
        } else {
            // Vérifier plusieurs tenants
            $query = Tenant::query();

            if (!$checkAll) {
                $query->active();
            }

            $tenants = $query->get();

            if ($tenants->isEmpty()) {
                $this->warn('⚠️  Aucun tenant à vérifier.');
                return 0;
            }

            $this->info("🏥 Vérification de la santé de {$tenants->count()} tenant(s)...");
            $this->newLine();

            $bar = $this->output->createProgressBar($tenants->count());
            $bar->start();

            foreach ($tenants as $tenant) {
                $this->performHealthChecks($tenant, $specificCheck, false);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
            $this->info('✅ Vérifications terminées !');
        }

        return 0;
    }

    private function performHealthChecks(Tenant $tenant, ?string $specificCheck = null, bool $verbose = true)
    {
        if ($verbose) {
            $this->info("🏥 Vérification de la santé de '{$tenant->code}'...");
            $this->newLine();
        }

        $checks = $specificCheck ? [$specificCheck] : self::CHECKS;
        $results = [];

        foreach ($checks as $checkType) {
            $result = match($checkType) {
                'http_status' => $this->checkHttpStatus($tenant),
                'database_connection' => $this->checkDatabaseConnection($tenant),
                'disk_space' => $this->checkDiskSpace($tenant),
                'ssl_certificate' => $this->checkSslCertificate($tenant),
                'application_errors' => $this->checkApplicationErrors($tenant),
                'queue_workers' => $this->checkQueueWorkers($tenant),
            };

            $results[] = $result;

            // Enregistrer le résultat dans la BDD
            TenantHealthCheckModel::create([
                'tenant_id' => $tenant->id,
                'check_type' => $checkType,
                'status' => $result['status'],
                'response_time_ms' => $result['response_time_ms'] ?? null,
                'details' => $result['details'] ?? null,
                'metadata' => $result['metadata'] ?? null,
            ]);
        }

        if ($verbose) {
            $this->displayResults($results);
        }
    }

    private function checkHttpStatus(Tenant $tenant): array
    {
        $url = "https://{$tenant->subdomain}.klassci.com";
        $startTime = microtime(true);

        try {
            $response = Http::timeout(10)->get($url);
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            $status = $response->successful() ? 'healthy' : 'unhealthy';

            return [
                'type' => 'http_status',
                'status' => $status,
                'response_time_ms' => $responseTime,
                'details' => "HTTP {$response->status()}",
                'metadata' => [
                    'status_code' => $response->status(),
                    'url' => $url,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'http_status',
                'status' => 'unhealthy',
                'response_time_ms' => null,
                'details' => "Erreur: {$e->getMessage()}",
                'metadata' => ['url' => $url, 'error' => $e->getMessage()],
            ];
        }
    }

    private function checkDatabaseConnection(Tenant $tenant): array
    {
        $credentials = $tenant->database_credentials;
        $startTime = microtime(true);

        try {
            config([
                'database.connections.tenant_temp' => [
                    'driver' => 'mysql',
                    'host' => $credentials['host'] ?? 'localhost',
                    'port' => $credentials['port'] ?? 3306,
                    'database' => $tenant->database_name,
                    'username' => $credentials['username'],
                    'password' => $credentials['password'],
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ]
            ]);

            DB::connection('tenant_temp')->getPdo();
            $tableCount = count(DB::connection('tenant_temp')->select('SHOW TABLES'));
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'type' => 'database_connection',
                'status' => 'healthy',
                'response_time_ms' => $responseTime,
                'details' => "Connexion OK ({$tableCount} tables)",
                'metadata' => [
                    'database' => $tenant->database_name,
                    'table_count' => $tableCount,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'database_connection',
                'status' => 'unhealthy',
                'response_time_ms' => null,
                'details' => "Erreur: {$e->getMessage()}",
                'metadata' => ['database' => $tenant->database_name, 'error' => $e->getMessage()],
            ];
        }
    }

    private function checkDiskSpace(Tenant $tenant): array
    {
        $path = env('PRODUCTION_PATH') . $tenant->code;

        if (!file_exists($path) || !is_dir($path)) {
            return [
                'type' => 'disk_space',
                'status' => 'unhealthy',
                'response_time_ms' => null,
                'details' => "Répertoire introuvable",
                'metadata' => ['path' => $path],
            ];
        }

        $usedMb = $tenant->current_storage_mb;
        $maxMb = $tenant->max_storage_mb;
        $usagePercent = $maxMb > 0 ? ($usedMb / $maxMb) * 100 : 0;

        $status = match(true) {
            $usagePercent >= 90 => 'unhealthy',
            $usagePercent >= 75 => 'degraded',
            default => 'healthy',
        };

        return [
            'type' => 'disk_space',
            'status' => $status,
            'response_time_ms' => null,
            'details' => sprintf('%.2f MB / %d MB (%.1f%%)', $usedMb, $maxMb, $usagePercent),
            'metadata' => [
                'used_mb' => $usedMb,
                'max_mb' => $maxMb,
                'usage_percent' => round($usagePercent, 2),
            ],
        ];
    }

    private function checkSslCertificate(Tenant $tenant): array
    {
        $url = "https://{$tenant->subdomain}.klassci.com";
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        try {
            $stream = @stream_socket_client(
                "ssl://{$tenant->subdomain}.klassci.com:443",
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$stream) {
                throw new \Exception("Impossible de se connecter: $errstr ($errno)");
            }

            $params = stream_context_get_params($stream);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

            $expiryDate = $cert['validTo_time_t'];
            $daysRemaining = (int) (($expiryDate - time()) / 86400);

            $status = match(true) {
                $daysRemaining < 7 => 'unhealthy',
                $daysRemaining < 30 => 'degraded',
                default => 'healthy',
            };

            return [
                'type' => 'ssl_certificate',
                'status' => $status,
                'response_time_ms' => null,
                'details' => "Expire dans {$daysRemaining} jours",
                'metadata' => [
                    'issuer' => $cert['issuer']['CN'] ?? 'Unknown',
                    'expires_at' => date('Y-m-d H:i:s', $expiryDate),
                    'days_remaining' => $daysRemaining,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'ssl_certificate',
                'status' => 'unhealthy',
                'response_time_ms' => null,
                'details' => "Erreur: {$e->getMessage()}",
                'metadata' => ['url' => $url, 'error' => $e->getMessage()],
            ];
        }
    }

    private function checkApplicationErrors(Tenant $tenant): array
    {
        $logPath = env('PRODUCTION_PATH') . $tenant->code . '/storage/logs/laravel.log';

        if (!file_exists($logPath)) {
            return [
                'type' => 'application_errors',
                'status' => 'degraded',  // ⚠️ Log file should exist - degraded status
                'response_time_ms' => null,
                'details' => "Aucun fichier de log (permissions ou config logging incorrecte)",
                'metadata' => [
                    'log_path' => $logPath,
                    'reason' => 'Log file does not exist - may indicate permission issues or logging misconfiguration',
                ],
            ];
        }

        // Configuration de la fenêtre temporelle (24h par défaut pour avoir plus de contexte)
        $timeWindow = 24 * 3600; // 24 heures
        $recentTime = time() - $timeWindow;

        // Lire les 500 dernières lignes via tail pour éviter de charger
        // l'intégralité du fichier log en mémoire (peut dépasser 256 MB)
        $logLines = [];
        if (function_exists('shell_exec')) {
            $raw = shell_exec('tail -n 500 ' . escapeshellarg($logPath) . ' 2>/dev/null');
            if ($raw !== null) {
                $logLines = explode("\n", $raw);
            }
        }
        // Fallback : lecture partielle via SplFileObject si shell_exec indisponible
        if (empty($logLines)) {
            $file = new \SplFileObject($logPath, 'r');
            $file->seek(PHP_INT_MAX);
            $total = $file->key();
            $start = max(0, $total - 500);
            $file->seek($start);
            while (!$file->eof()) {
                $logLines[] = $file->current();
                $file->next();
            }
        }

        // Compteurs par niveau de sévérité
        $errorsByLevel = [
            'EMERGENCY' => 0,
            'ALERT' => 0,
            'CRITICAL' => 0,
            'ERROR' => 0,
            'WARNING' => 0,
        ];

        // Compteurs par catégorie
        $errorsByCategory = [
            'sql' => 0,
            'php_exception' => 0,
            'http' => 0,
            'queue' => 0,
            'other' => 0,
        ];

        // Stocker les 5 erreurs les plus récentes avec détails
        $recentErrors = [];
        $errorCount = 0;

        // Pattern pour capturer tous les niveaux de log importants
        $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*\.(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING):\s*(.+)/';

        foreach ($logLines as $line) {
            if (preg_match($pattern, $line, $matches)) {
                $timestamp = strtotime($matches[1]);

                // Ignorer les erreurs en dehors de la fenêtre temporelle
                if ($timestamp < $recentTime) {
                    continue;
                }

                $level = $matches[2];
                $message = trim($matches[3]);

                // Compter par niveau
                if (isset($errorsByLevel[$level])) {
                    $errorsByLevel[$level]++;
                    $errorCount++;
                }

                // Catégoriser l'erreur
                $category = $this->categorizeError($message);
                $errorsByCategory[$category]++;

                // Stocker les 5 plus récentes (avec plus de contexte)
                if (count($recentErrors) < 5) {
                    $recentErrors[] = [
                        'timestamp' => $matches[1],
                        'level' => $level,
                        'message' => strlen($message) > 200 ? substr($message, 0, 200) . '...' : $message,
                        'category' => $category,
                    ];
                }
            }
        }

        // Calcul du statut avec pondération des niveaux critiques
        $criticalScore = ($errorsByLevel['EMERGENCY'] * 10)
                       + ($errorsByLevel['ALERT'] * 8)
                       + ($errorsByLevel['CRITICAL'] * 5)
                       + ($errorsByLevel['ERROR'] * 2)
                       + ($errorsByLevel['WARNING'] * 1);

        $status = match(true) {
            $errorsByLevel['EMERGENCY'] > 0 || $errorsByLevel['ALERT'] > 0 => 'unhealthy',
            $errorsByLevel['CRITICAL'] > 5 || $criticalScore > 100 => 'unhealthy',
            $errorsByLevel['ERROR'] > 50 || $criticalScore > 50 => 'degraded',
            $errorsByLevel['WARNING'] > 100 => 'degraded',
            default => 'healthy',
        };

        // Construction du message détaillé
        $totalErrors = $errorCount;
        $criticalCount = $errorsByLevel['EMERGENCY'] + $errorsByLevel['ALERT'] + $errorsByLevel['CRITICAL'];

        $details = match(true) {
            $totalErrors === 0 => "Aucune erreur détectée (24h)",
            $criticalCount > 0 => "{$totalErrors} erreurs ({$criticalCount} critiques) dans les 24h",
            default => "{$totalErrors} erreurs dans les 24h",
        };

        return [
            'type' => 'application_errors',
            'status' => $status,
            'response_time_ms' => null,
            'details' => $details,
            'metadata' => [
                'log_path' => $logPath,
                'time_window' => '24 heures',
                'total_errors' => $totalErrors,
                'errors_by_level' => array_filter($errorsByLevel), // Retirer les 0
                'errors_by_category' => array_filter($errorsByCategory),
                'recent_errors' => $recentErrors,
                'critical_score' => $criticalScore,
            ],
        ];
    }

    /**
     * Catégoriser une erreur selon son message
     */
    private function categorizeError(string $message): string
    {
        // Patterns pour identifier les catégories
        $patterns = [
            'sql' => '/SQLSTATE|Query|Database|PDOException|Integrity constraint/i',
            'php_exception' => '/Exception|Error|Fatal|Call to undefined|Class .* not found/i',
            'http' => '/HTTP|cURL|GuzzleHttp|Response|RequestException/i',
            'queue' => '/Queue|Job|Worker|Redis|Horizon/i',
        ];

        foreach ($patterns as $category => $pattern) {
            if (preg_match($pattern, $message)) {
                return $category;
            }
        }

        return 'other';
    }

    private function checkQueueWorkers(Tenant $tenant): array
    {
        // Cette vérification nécessite accès au système de queue (Redis/Database)
        // Pour l'instant, on simule la vérification
        // TODO: Implémenter vérification réelle si queues configurées

        return [
            'type' => 'queue_workers',
            'status' => 'healthy',
            'response_time_ms' => null,
            'details' => "Vérification non implémentée (à venir)",
            'metadata' => ['note' => 'Queue monitoring requires Redis/Database queue configuration'],
        ];
    }

    private function displayResults(array $results): void
    {
        $tableData = [];

        foreach ($results as $result) {
            $statusIcon = match($result['status']) {
                'healthy' => '✅',
                'degraded' => '⚠️',
                'unhealthy' => '❌',
            };

            $tableData[] = [
                $result['type'],
                "{$statusIcon} {$result['status']}",
                $result['details'] ?? 'N/A',
                $result['response_time_ms'] ? "{$result['response_time_ms']} ms" : 'N/A',
            ];
        }

        $this->table(
            ['Type de vérification', 'Statut', 'Détails', 'Temps de réponse'],
            $tableData
        );

        $this->newLine();

        // Résumé global
        $healthyCount = count(array_filter($results, fn($r) => $r['status'] === 'healthy'));
        $degradedCount = count(array_filter($results, fn($r) => $r['status'] === 'degraded'));
        $unhealthyCount = count(array_filter($results, fn($r) => $r['status'] === 'unhealthy'));

        $globalStatus = match(true) {
            $unhealthyCount > 0 => '❌ CRITIQUE',
            $degradedCount > 0 => '⚠️  DÉGRADÉ',
            default => '✅ SAIN',
        };

        $this->info("Statut global: {$globalStatus}");
        $this->line("  - Sain: {$healthyCount}");
        if ($degradedCount > 0) $this->line("  - Dégradé: {$degradedCount}");
        if ($unhealthyCount > 0) $this->line("  - Critique: {$unhealthyCount}");
        $this->newLine();
    }
}
