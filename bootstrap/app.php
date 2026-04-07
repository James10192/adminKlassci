<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant.api' => \App\Http\Middleware\VerifyTenantApiToken::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Mise à jour automatique des stats de tous les tenants actifs toutes les heures
        $schedule->command('tenant:update-stats')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tenant-stats-updates.log'));

        // Vérification quotidienne des alertes KPI pour les groupes
        $schedule->command('group:alert-check')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/group-alert-check.log'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withProviders([
        \App\Providers\Filament\AdminPanelProvider::class,
        \App\Providers\Filament\GroupPanelProvider::class,
    ])
    ->create();
