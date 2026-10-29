<?php

namespace App\Providers;

use App\Services\Saas\SaasCapabilities;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class SaasServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([SaasCapabilities::VIEW, SaasCapabilities::PROPOSE, SaasCapabilities::REVIEW] as $ability) {
            Gate::define($ability, fn ($user) => SaasCapabilities::allows($user, $ability));
        }
    }
}
