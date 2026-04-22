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
//   * * * * * /opt/alt/php83/usr/bin/php /home/c2569688c/public_html/admin/artisan schedule:run >> /dev/null 2>&1
//
// En développement : php artisan schedule:work
// ============================================

/**
 * Helper : ajoute un horodatage avant/après chaque tâche dans le log.
 * Guarded against double-declaration when the app boots multiple times (tests, tinker reruns).
 */
if (!function_exists('scheduleWithTimestamp')) {
    function scheduleWithTimestamp($event, string $logFile, string $taskName)
    {
        return $event
            ->before(function () use ($logFile, $taskName) {
                $ts = now()->format('Y-m-d H:i:s');
                file_put_contents($logFile, "\n[{$ts}] ▶ {$taskName} — début\n", FILE_APPEND);
            })
            ->after(function () use ($logFile, $taskName) {
                $ts = now()->format('Y-m-d H:i:s');
                file_put_contents($logFile, "[{$ts}] ■ {$taskName} — fin\n", FILE_APPEND);
            })
            ->appendOutputTo($logFile);
    }
}

// 1. Health Check — toutes les heures
scheduleWithTimestamp(
    Schedule::command('tenant:health-check --all')
        ->hourly()
        ->withoutOverlapping()
        ->runInBackground()
        ->onSuccess(fn () => \Log::info('✅ Health checks terminés'))
        ->onFailure(fn () => \Log::error('❌ Échec des health checks')),
    storage_path('logs/health-checks.log'),
    'tenant:health-check --all'
);

// 2. Backup DB quotidien — chaque nuit à 02h00
scheduleWithTimestamp(
    Schedule::command('tenant:backup --all --type=database_only')
        ->dailyAt('02:00')
        ->withoutOverlapping()
        ->runInBackground()
        ->onSuccess(fn () => \Log::info('✅ Backups DB quotidiens terminés'))
        ->onFailure(fn () => \Log::error('❌ Échec des backups DB quotidiens')),
    storage_path('logs/backups.log'),
    'tenant:backup --all --type=database_only'
);

// 3. Backup complet (DB + fichiers) — chaque dimanche à 03h00
scheduleWithTimestamp(
    Schedule::command('tenant:backup --all --type=full')
        ->weekly()
        ->sundays()
        ->at('03:00')
        ->withoutOverlapping()
        ->runInBackground()
        ->onSuccess(fn () => \Log::info('✅ Backup full hebdomadaire terminé'))
        ->onFailure(fn () => \Log::error('❌ Échec du backup full hebdomadaire')),
    storage_path('logs/backups.log'),
    'tenant:backup --all --type=full'
);

// 4. Nettoyage des backups expirés — chaque jour à 04h00 (après les backups)
scheduleWithTimestamp(
    Schedule::command('tenant:cleanup-backups --days=30')
        ->dailyAt('04:00')
        ->withoutOverlapping()
        ->runInBackground()
        ->onSuccess(fn () => \Log::info('✅ Nettoyage des backups terminé'))
        ->onFailure(fn () => \Log::error('❌ Échec du nettoyage des backups')),
    storage_path('logs/backups.log'),
    'tenant:cleanup-backups --days=30'
);

// 5. Mise à jour des statistiques tenants — toutes les heures
scheduleWithTimestamp(
    Schedule::command('tenant:update-stats --all')
        ->hourly()
        ->withoutOverlapping()
        ->runInBackground(),
    storage_path('logs/stats.log'),
    'tenant:update-stats --all'
);

// 6. Alertes tenants — chaque jour à 09h00 (quota dépassé, expiration, inactivité)
scheduleWithTimestamp(
    Schedule::command('tenant:send-alerts')
        ->dailyAt('09:00')
        ->withoutOverlapping()
        ->runInBackground()
        ->onSuccess(fn () => \Log::info('✅ Alertes tenants envoyées'))
        ->onFailure(fn () => \Log::error('❌ Échec de l\'envoi des alertes')),
    storage_path('logs/alerts.log'),
    'tenant:send-alerts'
);

// 7. Rotation des logs tenants — chaque dimanche à 01h00
scheduleWithTimestamp(
    Schedule::command('tenant:rotate-logs --days=30')
        ->weekly()
        ->sundays()
        ->at('01:00')
        ->withoutOverlapping()
        ->runInBackground()
        ->onSuccess(fn () => \Log::info('✅ Rotation des logs terminée'))
        ->onFailure(fn () => \Log::error('❌ Échec de la rotation des logs')),
    storage_path('logs/rotate-logs.log'),
    'tenant:rotate-logs --days=30'
);

// ============================================
// Group portal email notifications (PR-C)
// ============================================

// Immediate critical alerts — every 15 min (alerts already cached 5 min,
// so this means up to 20 min lag end-to-end). Skipped entirely when the
// feature flag is off.
scheduleWithTimestamp(
    Schedule::command('group:dispatch-alert-notifications')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->runInBackground()
        ->skip(fn () => ! config('group_portal.notifications_enabled', false))
        ->onSuccess(fn () => \Log::info('✅ Group immediate alerts dispatched'))
        ->onFailure(fn () => \Log::error('❌ Group immediate alerts dispatch failed')),
    storage_path('logs/group-notifications.log'),
    'group:dispatch-alert-notifications'
);

// Digest sweep — every 30 min the dispatcher checks each member's
// digest_time slot; actual send happens once per day when the slot matches.
scheduleWithTimestamp(
    Schedule::command('group:send-alert-digests')
        ->everyThirtyMinutes()
        ->withoutOverlapping()
        ->runInBackground()
        ->skip(fn () => ! config('group_portal.notifications_enabled', false))
        ->onSuccess(fn () => \Log::info('✅ Group digests dispatched'))
        ->onFailure(fn () => \Log::error('❌ Group digests dispatch failed')),
    storage_path('logs/group-digests.log'),
    'group:send-alert-digests'
);

// Storage ingestion — nightly run populates tenants.current_storage_mb
// from real disk usage. Once populated, collectQuotaAlerts fires storage
// alerts naturally. Skipped when the feature flag is off.
scheduleWithTimestamp(
    Schedule::command('tenant:update-storage')
        ->dailyAt('03:30')
        ->withoutOverlapping()
        ->runInBackground()
        ->skip(fn () => ! config('group_portal.storage_ingestion_enabled', false))
        ->onSuccess(fn () => \Log::info('✅ Tenant storage ingestion complete'))
        ->onFailure(fn () => \Log::error('❌ Tenant storage ingestion failed')),
    storage_path('logs/storage-ingestion.log'),
    'tenant:update-storage'
);
