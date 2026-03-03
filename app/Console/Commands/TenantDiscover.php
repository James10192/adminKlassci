<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantActivityLog;
use Illuminate\Console\Command;

class TenantDiscover extends Command
{
    protected $signature = 'tenant:discover
                            {--dry-run : Afficher les tenants trouvés sans les importer}';

    protected $description = 'Scanner PRODUCTION_PATH et importer automatiquement les tenants présents sur disque mais absents en BDD';

    public function handle(): int
    {
        $productionPath = rtrim(env('PRODUCTION_PATH', ''), '/');

        if (!$productionPath) {
            $this->error('❌ PRODUCTION_PATH n\'est pas défini dans le .env.');
            return 1;
        }

        if (!is_dir($productionPath)) {
            $this->error("❌ Le répertoire PRODUCTION_PATH est introuvable : {$productionPath}");
            return 1;
        }

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('⚠️  Mode dry-run activé — aucune modification en base de données.');
            $this->newLine();
        }

        $this->info("🔍 Scan de {$productionPath}...");
        $this->newLine();

        // Lister tous les sous-dossiers de PRODUCTION_PATH
        $directories = glob("{$productionPath}/*", GLOB_ONLYDIR);

        if (empty($directories)) {
            $this->warn('Aucun dossier trouvé dans PRODUCTION_PATH.');
            return 0;
        }

        // Dossiers à exclure explicitement (le panel admin lui-même + dossiers système)
        $excluded = ['admin', 'cgi-bin', '.well-known', 'tmp', 'logs', 'error_log'];

        $imported  = 0;
        $skipped   = 0;
        $alreadyKnown = 0;
        $errors    = [];

        foreach ($directories as $dir) {
            $code = basename($dir);

            // Exclure les dossiers système
            if (in_array($code, $excluded)) {
                $this->line("  <fg=gray>→ Ignoré (exclu) : {$code}</>");
                continue;
            }

            // Vérifier la présence d'un .env
            $envPath = "{$dir}/.env";
            if (!file_exists($envPath)) {
                $this->line("  <fg=yellow>⚠  Pas de .env : {$code}</>");
                $skipped++;
                continue;
            }

            // Parser le .env
            $env = $this->parseEnvFile($envPath);

            // Vérifier que c'est bien une application Laravel (APP_KEY présent)
            if (empty($env['APP_KEY'])) {
                $this->line("  <fg=yellow>⚠  Pas d'APP_KEY (non-Laravel ?) : {$code}</>");
                $skipped++;
                continue;
            }

            // Vérifier si le tenant existe déjà en BDD
            if (Tenant::where('code', $code)->exists()) {
                $this->line("  <fg=gray>✓ Déjà connu : {$code}</>");
                $alreadyKnown++;
                continue;
            }

            // Extraire les informations depuis le .env
            $name      = $env['APP_NAME'] ?? $code;
            $appUrl    = $env['APP_URL'] ?? "https://{$code}.klassci.com";
            $subdomain = $this->extractSubdomain($appUrl, $code);
            $dbName    = $env['DB_DATABASE'] ?? "c2569688c_{$code}";
            $gitBranch = $env['GIT_BRANCH'] ?? $env['APP_BRANCH'] ?? 'presentation';

            // Vérifier unicité du subdomain
            if (Tenant::where('subdomain', $subdomain)->exists()) {
                $msg = "Subdomain '{$subdomain}' déjà utilisé pour le dossier '{$code}'";
                $errors[] = $msg;
                $this->line("  <fg=red>✗ {$msg}</>");
                $skipped++;
                continue;
            }

            if ($isDryRun) {
                $this->line("  <fg=green>+ Serait importé : {$code}</> → {$name} ({$subdomain}.klassci.com)");
                $imported++;
                continue;
            }

            // Créer le tenant en BDD
            try {
                $tenant = Tenant::create([
                    'code'               => $code,
                    'name'               => $name,
                    'subdomain'          => $subdomain,
                    'database_name'      => $dbName,
                    'database_credentials' => [
                        'host'     => $env['DB_HOST'] ?? 'localhost',
                        'port'     => (int) ($env['DB_PORT'] ?? 3306),
                        'username' => $env['DB_USERNAME'] ?? '',
                        'password' => $env['DB_PASSWORD'] ?? '',
                    ],
                    'git_branch'         => $gitBranch,
                    'status'             => 'active',
                    'plan'               => 'free',
                    'monthly_fee'        => 0,
                    'max_users'          => 5,
                    'max_staff'          => 5,
                    'max_students'       => 50,
                    'max_inscriptions_per_year' => 50,
                    'max_storage_mb'     => 512,
                    'admin_email'        => $env['MAIL_FROM_ADDRESS'] ?? null,
                ]);

                TenantActivityLog::create([
                    'tenant_id'   => $tenant->id,
                    'action'      => 'tenant_discovered',
                    'description' => "Tenant importé automatiquement via tenant:discover",
                    'metadata'    => [
                        'source'      => 'disk_scan',
                        'env_path'    => $envPath,
                        'app_url'     => $appUrl,
                        'db_name'     => $dbName,
                        'git_branch'  => $gitBranch,
                    ],
                ]);

                $this->line("  <fg=green>✅ Importé : {$code}</> → {$name}");
                $imported++;

            } catch (\Exception $e) {
                $msg = "Erreur création tenant '{$code}' : " . $e->getMessage();
                $errors[] = $msg;
                $this->line("  <fg=red>✗ {$msg}</>");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("📊 Résultat du scan :");
        $this->table(
            ['Statut', 'Nombre'],
            [
                [$isDryRun ? '+ Seraient importés' : '✅ Importés', $imported],
                ['✓ Déjà connus',  $alreadyKnown],
                ['⚠  Ignorés/Erreurs', $skipped],
            ]
        );

        if (!empty($errors)) {
            $this->newLine();
            $this->warn('Erreurs rencontrées :');
            foreach ($errors as $error) {
                $this->line("  • {$error}");
            }
        }

        return 0;
    }

    /**
     * Parse a .env file and return key-value pairs.
     * Handles quoted values, comments, and blank lines.
     */
    private function parseEnvFile(string $path): array
    {
        $result = [];
        $lines  = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return $result;
        }

        foreach ($lines as $line) {
            // Skip comments
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Only parse KEY=VALUE lines
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip surrounding quotes (" or ')
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[-1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Remove inline comments (value # comment)
            if (str_contains($value, ' #')) {
                $value = trim(explode(' #', $value, 2)[0]);
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Extract subdomain from APP_URL.
     * e.g. "https://esbtp-abidjan.klassci.com" → "esbtp-abidjan"
     *      "http://localhost" → fallback to $code
     */
    private function extractSubdomain(string $appUrl, string $fallback): string
    {
        $host = parse_url($appUrl, PHP_URL_HOST) ?? '';

        // Strip .klassci.com suffix
        if (str_ends_with($host, '.klassci.com')) {
            return substr($host, 0, -strlen('.klassci.com'));
        }

        // If no recognizable pattern, use the folder code
        return $fallback;
    }
}
