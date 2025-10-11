<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use Illuminate\Console\Command;

class TestTenantConnection extends Command
{
    protected $signature = 'tenant:test-connection {code}';

    protected $description = 'Test database connection to a tenant';

    public function handle(TenantConnectionManager $manager)
    {
        $code = $this->argument('code');

        $this->info("Testing connection for tenant: {$code}");

        $tenant = Tenant::where('code', $code)->first();

        if (!$tenant) {
            $this->error("Tenant '{$code}' not found");
            return 1;
        }

        $this->info("Tenant found: {$tenant->name}");
        $this->info("Database: {$tenant->database_name}");
        $this->info("Host: " . ($tenant->database_credentials['host'] ?? 'N/A'));

        $this->newLine();
        $this->info("Testing connection...");

        try {
            $result = $manager->testConnection($tenant);

            if ($result) {
                $this->info("✓ Connection test: SUCCESS");

                $this->newLine();
                $this->info("Retrieving stats...");

                $stats = $manager->getTenantStats($tenant);

                $this->table(
                    ['Metric', 'Current', 'Max', 'Status'],
                    [
                        ['Users', $stats['current_users'], $tenant->max_users, $stats['current_users'] > $tenant->max_users ? '⚠ Over' : '✓ OK'],
                        ['Staff', $stats['current_staff'], $tenant->max_staff, $stats['current_staff'] > $tenant->max_staff ? '⚠ Over' : '✓ OK'],
                        ['Students', $stats['current_students'], $tenant->max_students, $stats['current_students'] > $tenant->max_students ? '⚠ Over' : '✓ OK'],
                        ['Storage (MB)', $stats['current_storage_mb'], $tenant->max_storage_mb, $stats['current_storage_mb'] > $tenant->max_storage_mb ? '⚠ Over' : '✓ OK'],
                    ]
                );

                $this->newLine();
                $this->info("Updating tenant stats in klassci_master...");
                $manager->updateTenantStats($tenant);
                $this->info("✓ Stats updated successfully");

                return 0;
            } else {
                $this->error("✗ Connection test: FAILED");
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->newLine();
            $this->error("Full trace:");
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
