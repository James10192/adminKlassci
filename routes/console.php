<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// ============================================
// TÂCHES PLANIFIÉES AUTOMATIQUES
// ============================================

// 1. Health Check - Toutes les 5 minutes
Schedule::command('tenant:health-check --all')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// 2. Backups automatiques - Quotidien à 2h du matin (Base de données uniquement)
Schedule::command('tenant:backup --all --type=database_only')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Log::info('✅ Backups automatiques quotidiens terminés avec succès');
    })
    ->onFailure(function () {
        \Log::error('❌ Échec des backups automatiques quotidiens');
    });

// 3. Nettoyage backups expirés - Quotidien à 3h du matin (rétention 30 jours)
Schedule::command('tenant:cleanup-backups --days=30')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Log::info('✅ Nettoyage des backups expirés terminé avec succès');
    })
    ->onFailure(function () {
        \Log::error('❌ Échec du nettoyage des backups expirés');
    });

// 4. Mise à jour des statistiques des tenants - Toutes les heures
Schedule::command('tenant:update-stats --all')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// 5. Alertes tenants - Quotidien à 8h (quota dépassé, expiration, inactivité)
Schedule::command('tenant:send-alerts')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Log::info('✅ Alertes tenants envoyées avec succès');
    });

// ============================================
// NOTES:
// - Pour activer en production: ajouter au crontab
//   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
// - En développement: php artisan schedule:work
// ============================================
