<?php

namespace App\Filament\Group\Widgets;

use App\Models\Tenant;
use App\Services\SsoTokenSigner;
use App\Services\TenantAggregationService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Log;

class EstablishmentCardsWidget extends Widget
{
    protected static string $view = 'filament.group.widgets.establishment-cards';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '300s';

    public function getEstablishments(): array
    {
        $group = auth('group')->user()->group;
        $kpis = app(TenantAggregationService::class)->getGroupKpis($group);

        return $kpis['establishments'] ?? [];
    }

    /**
     * Generate a fresh SSO URL for each tenant card. Token lifetime is 2min
     * (see SsoTokenSigner); widget polls every 300s so a click >2min after the
     * last render requires a refresh — acceptable tradeoff for security.
     */
    public function getSsoUrl(string $tenantCode, string $redirectTo = '/'): ?string
    {
        $member = auth('group')->user();
        if (! $member) {
            return null;
        }

        $tenant = Tenant::where('code', $tenantCode)->first();
        if (! $tenant) {
            return null;
        }

        try {
            $token = app(SsoTokenSigner::class)->sign([
                'tenant_code' => $tenantCode,
                'user_email' => $member->email,
                'redirect_to' => $redirectTo,
                'issued_by' => $member->email,
                'group_member_id' => $member->id,
            ]);
        } catch (\Exception $e) {
            Log::warning("SSO URL generation failed for {$tenantCode}: {$e->getMessage()}");
            return null;
        }

        $baseUrl = $this->tenantBaseUrl($tenant);

        return $baseUrl . '/auth/sso-from-group?token=' . urlencode($token);
    }

    private function tenantBaseUrl(Tenant $tenant): string
    {
        $metadata = $tenant->metadata ?? [];
        if (isset($metadata['base_url']) && is_string($metadata['base_url'])) {
            return rtrim($metadata['base_url'], '/');
        }

        $subdomain = $tenant->subdomain ?? $tenant->code;
        return "https://{$subdomain}.klassci.com";
    }
}
