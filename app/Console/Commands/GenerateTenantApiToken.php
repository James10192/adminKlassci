<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateTenantApiToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:generate-token {code : Tenant code}
                            {--regenerate : Regenerate token even if one exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate API token for a tenant to access Master API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $code = $this->argument('code');
        $regenerate = $this->option('regenerate');

        // Find tenant
        $tenant = Tenant::where('code', $code)->first();

        if (!$tenant) {
            $this->error("❌ Tenant '{$code}' not found");
            return 1;
        }

        // Check if token already exists
        if ($tenant->api_token && !$regenerate) {
            $this->warn("⚠️  Tenant '{$code}' already has an API token");
            $this->line("Use --regenerate to generate a new token");
            $this->newLine();
            $this->info("Current token: {$tenant->api_token}");
            return 0;
        }

        // Generate new token
        $token = Str::random(64);

        $tenant->update([
            'api_token' => $token,
            'api_token_created_at' => now(),
        ]);

        $this->newLine();
        $this->info("✅ API token generated successfully for tenant '{$tenant->name}' ({$code})");
        $this->newLine();

        // Display token in a box
        $this->line('┌─────────────────────────────────────────────────────────────────────┐');
        $this->line('│ API Token (save this securely - it won\'t be shown again!)          │');
        $this->line('├─────────────────────────────────────────────────────────────────────┤');
        $this->line("│ {$token}  │");
        $this->line('└─────────────────────────────────────────────────────────────────────┘');
        $this->newLine();

        // Display usage instructions
        $this->info('📋 Usage Instructions:');
        $this->newLine();
        $this->line('1. Add to tenant .env file:');
        $this->line('   MASTER_API_URL=' . url('/api'));
        $this->line("   MASTER_API_TOKEN={$token}");
        $this->line("   TENANT_CODE={$code}");
        $this->newLine();

        $this->line('2. Test API endpoint:');
        $apiUrl = url("/api/tenants/{$code}/limits");
        $this->line("   curl -H \"Authorization: Bearer {$token}\" {$apiUrl}");
        $this->newLine();

        $this->line('3. Alternative (query parameter):');
        $this->line("   curl \"{$apiUrl}?token={$token}\"");
        $this->newLine();

        if ($regenerate) {
            $this->warn('⚠️  Previous token has been invalidated and will no longer work!');
        }

        return 0;
    }
}
