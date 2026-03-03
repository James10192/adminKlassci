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
        // Toutes les tâches planifiées sont définies dans routes/console.php
        // (format Laravel 11+). Ne pas ajouter de tâches ici pour éviter les doublons.
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
