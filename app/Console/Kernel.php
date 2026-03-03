<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * Pré-requis cPanel : ajouter cette ligne dans "Cron Jobs" (cPanel > Avancé > Tâches Cron) :
     *   * * * * * /usr/local/bin/php /home/c2569688c/public_html/admin/artisan schedule:run >> /dev/null 2>&1
     */
    protected function schedule(Schedule $schedule): void
    {
        // ─────────────────────────────────────────────────
        // Health Checks — toutes les heures
        // ─────────────────────────────────────────────────
        $schedule->command('tenant:health-check --all')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/health-checks.log'));

        // ─────────────────────────────────────────────────
        // Backup DB quotidien — chaque nuit à 02h00
        // ─────────────────────────────────────────────────
        $schedule->command('tenant:backup --type=database_only')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/backups.log'));

        // ─────────────────────────────────────────────────
        // Backup complet (DB + fichiers) — chaque dimanche à 03h00
        // ─────────────────────────────────────────────────
        $schedule->command('tenant:backup --type=full')
            ->weekly()
            ->sundays()
            ->at('03:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/backups.log'));

        // ─────────────────────────────────────────────────
        // Nettoyage des backups expirés — chaque jour à 04h00
        // ─────────────────────────────────────────────────
        $schedule->command('tenant:cleanup-backups --days=30')
            ->dailyAt('04:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/backups.log'));

        // ─────────────────────────────────────────────────
        // Mise à jour des statistiques tenants — chaque jour à 01h00
        // ─────────────────────────────────────────────────
        $schedule->command('tenant:update-stats --all')
            ->dailyAt('01:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/stats.log'));

        // ─────────────────────────────────────────────────
        // Alertes tenants — chaque jour à 09h00
        // ─────────────────────────────────────────────────
        $schedule->command('tenant:send-alerts')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/alerts.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
