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
            'care.instance' => \App\Http\Middleware\Care\AuthentifierInstance::class,
            'cli.capacite' => \App\Http\Middleware\Cli\ExigerCapaciteCli::class,
        ]);
        $middleware->api(prepend: [\App\Http\Middleware\AttribuerIdentifiantRequete::class]);
        // Aucune route `login` : un invite qui suit un lien protege par `auth` (une
        // piece jointe ouverte apres expiration de la session) va a la connexion
        // du panel dont il releve (admin ou portail groupe), au lieu d'une erreur
        // 500 sur route('login').
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => route(
            $request->is('groupe', 'groupe/*') ? 'filament.group.auth.login' : 'filament.admin.auth.login'
        ));
        // L'identifiant d'instance doit etre resolu AVANT la limite de debit,
        // qui compte par instance : sinon toutes les ecoles partagent un compteur.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\ThrottleRequests::class,
            prepend: \App\Http\Middleware\Care\AuthentifierInstance::class,
        );
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
        \App\Providers\GroupServiceProvider::class,
        \App\Providers\CareServiceProvider::class,
    ])
    ->create();
