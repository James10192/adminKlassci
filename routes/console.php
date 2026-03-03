<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// ============================================
// TÂCHES PLANIFIÉES AUTOMATIQUES
//
// Prérequis cPanel : ajouter dans "Cron Jobs" (cPanel > Avancé > Tâches Cron) :
//   * * * * * /usr/local/bin/php /home/c2569688c/public_html/admin/artisan schedule:run >> /dev/null 2>&1
//
// En développement : php artisan schedule:work
// ============================================

// 1. Health Check — toutes les heures
Schedule::command('tenant:health-check --all')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/health-checks.log'))
    ->onSuccess(function () {
        \Log::info('✅ Health checks terminés avec succès');
    })
    ->onFailure(function () {
        \Log::error('❌ Échec des health checks');
    });

// 2. Backup DB quotidien — chaque nuit à 02h00
Schedule::command('tenant:backup --all --type=database_only')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/backups.log'))
    ->onSuccess(function () {
        \Log::info('✅ Backups DB quotidiens terminés avec succès');
    })
    ->onFailure(function () {
        \Log::error('❌ Échec des backups DB quotidiens');
    });

// 3. Backup complet (DB + fichiers) — chaque dimanche à 03h00
Schedule::command('tenant:backup --all --type=full')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/backups.log'))
    ->onSuccess(function () {
        \Log::info('✅ Backup full hebdomadaire terminé avec succès');
    })
    ->onFailure(function () {
        \Log::error('❌ Échec du backup full hebdomadaire');
    });

// 4. Nettoyage des backups expirés — chaque jour à 04h00 (après les backups)
Schedule::command('tenant:cleanup-backups --days=30')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/backups.log'))
    ->onSuccess(function () {
        \Log::info('✅ Nettoyage des backups expirés terminé avec succès');
    })
    ->onFailure(function () {
        \Log::error('❌ Échec du nettoyage des backups expirés');
    });

// 5. Mise à jour des statistiques tenants — toutes les heures
Schedule::command('tenant:update-stats --all')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/stats.log'));

// 6. Alertes tenants — chaque jour à 09h00 (quota dépassé, expiration, inactivité)
Schedule::command('tenant:send-alerts')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/alerts.log'))
    ->onSuccess(function () {
        \Log::info('✅ Alertes tenants envoyées avec succès');
    })
    ->onFailure(function () {
        \Log::error('❌ Échec de l\'envoi des alertes tenants');
    });

// 7. Rotation des logs tenants — chaque dimanche à 01h00 (avant le health-check et les backups)
//    Conserve 30 jours de logs ; libère la mémoire pour les commandes suivantes.
Schedule::command('tenant:rotate-logs --days=30')
    ->weekly()
    ->sundays()
    ->at('01:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/rotate-logs.log'))
    ->onSuccess(function () {
        \Log::info('✅ Rotation des logs tenants terminée avec succès');
    })
    ->onFailure(function () {
        \Log::error('❌ Échec de la rotation des logs tenants');
    });
