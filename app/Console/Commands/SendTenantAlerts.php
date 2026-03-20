<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\TenantHealthCheck;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Notifications\Actions\Action;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendTenantAlerts extends Command
{
    protected $signature = 'tenant:send-alerts
                            {--dry-run : Affiche les alertes sans les envoyer}
                            {--no-email : Désactiver l\'envoi d\'email (notifications Filament uniquement)}';

    protected $description = 'Envoie des notifications aux admins SaaS (Filament + email) pour les quotas dépassés, abonnements expirants et tenants inactifs.';

    public function handle(): int
    {
        $admins = User::where('is_active', true)->get();

        if ($admins->isEmpty()) {
            $this->warn('Aucun admin actif trouvé.');
            return 0;
        }

        $dryRun = $this->option('dry-run');
        $alertCount = 0;
        $emailAlerts = []; // Collecte pour l'email récapitulatif

        $activeTenants = Tenant::where('status', 'active')->get();

        foreach ($activeTenants as $tenant) {
            // --- 1. Quota dépassé ---
            if ($tenant->isOverQuota()) {
                $quotaDetails = $this->buildQuotaDetails($tenant);

                $this->info("🔴 Quota dépassé: {$tenant->name} — {$quotaDetails}");
                $alertCount++;
                $emailAlerts[] = ['level' => 'danger', 'tenant' => $tenant->name, 'title' => 'Quota dépassé', 'message' => $quotaDetails];

                if (!$dryRun) {
                    foreach ($admins as $admin) {
                        Notification::make()
                            ->danger()
                            ->title("Quota dépassé : {$tenant->name}")
                            ->body($quotaDetails)
                            ->icon('heroicon-o-exclamation-circle')
                            ->actions([
                                Action::make('view')
                                    ->label('Voir l\'établissement')
                                    ->url(route('filament.admin.resources.tenants.view', $tenant->id))
                                    ->button(),
                            ])
                            ->sendToDatabase($admin);
                    }
                }
            }

            // --- 2. Abonnement déjà expiré ---
            if ($tenant->subscription_end_date && $tenant->subscription_end_date->isPast()) {
                $expiryDate = $tenant->subscription_end_date->format('d/m/Y');
                $daysExpired = (int) $tenant->subscription_end_date->diffInDays(now());
                $message = "L'abonnement a expiré depuis {$daysExpired} jour(s) (le {$expiryDate}).";

                $this->error("🚨 Abonnement expiré: {$tenant->name} — {$message}");
                $alertCount++;
                $emailAlerts[] = ['level' => 'danger', 'tenant' => $tenant->name, 'title' => 'Abonnement expiré', 'message' => $message];

                if (!$dryRun) {
                    foreach ($admins as $admin) {
                        Notification::make()
                            ->danger()
                            ->title("Abonnement expiré : {$tenant->name}")
                            ->body($message)
                            ->icon('heroicon-o-x-circle')
                            ->actions([
                                Action::make('renew')
                                    ->label('Renouveler')
                                    ->url(route('filament.admin.resources.tenants.edit', $tenant->id))
                                    ->button(),
                            ])
                            ->sendToDatabase($admin);
                    }
                }
            }
            // --- 3. Abonnement expirant dans ≤ 30 jours (mais pas encore expiré) ---
            elseif ($tenant->subscription_end_date) {
                $daysUntilExpiry = (int) now()->diffInDays($tenant->subscription_end_date, false);

                if ($daysUntilExpiry >= 0 && $daysUntilExpiry <= 30) {
                    $expiryDate = $tenant->subscription_end_date->format('d/m/Y');
                    $message = "L'abonnement expire dans {$daysUntilExpiry} jour(s) ({$expiryDate}).";

                    $this->warn("⚠️  Expiration proche: {$tenant->name} — {$message}");
                    $alertCount++;
                    $emailAlerts[] = ['level' => 'warning', 'tenant' => $tenant->name, 'title' => 'Abonnement expirant', 'message' => $message];

                    if (!$dryRun) {
                        foreach ($admins as $admin) {
                            Notification::make()
                                ->warning()
                                ->title("Abonnement expirant : {$tenant->name}")
                                ->body($message)
                                ->icon('heroicon-o-calendar-days')
                                ->actions([
                                    Action::make('view')
                                        ->label('Renouveler')
                                        ->url(route('filament.admin.resources.tenants.edit', $tenant->id))
                                        ->button(),
                                ])
                                ->sendToDatabase($admin);
                        }
                    }
                }
            }

            // --- 4. Tenant inactif depuis 7 jours ---
            $daysSinceUpdate = $tenant->updated_at
                ? (int) now()->diffInDays($tenant->updated_at)
                : null;

            if ($daysSinceUpdate !== null && $daysSinceUpdate >= 7) {
                $lastUpdate = $tenant->updated_at->diffForHumans();
                $message = "Aucune activité détectée depuis {$daysSinceUpdate} jours (dernière mise à jour : {$lastUpdate}).";

                $this->line("💤 Inactif: {$tenant->name} — {$message}");
                $alertCount++;
                $emailAlerts[] = ['level' => 'info', 'tenant' => $tenant->name, 'title' => 'Tenant inactif', 'message' => $message];

                if (!$dryRun) {
                    foreach ($admins as $admin) {
                        Notification::make()
                            ->info()
                            ->title("Tenant inactif : {$tenant->name}")
                            ->body($message)
                            ->icon('heroicon-o-clock')
                            ->actions([
                                Action::make('view')
                                    ->label('Voir le tenant')
                                    ->url(route('filament.admin.resources.tenants.view', $tenant->id))
                                    ->button(),
                            ])
                            ->sendToDatabase($admin);
                    }
                }
            }

            // --- 5. Health check unhealthy (HTTP ou DB) ---
            $unhealthyCheck = TenantHealthCheck::where('tenant_id', $tenant->id)
                ->whereIn('check_type', ['http_status', 'database_connection'])
                ->where('status', 'unhealthy')
                ->where('checked_at', '>=', now()->subHours(2))
                ->orderByDesc('checked_at')
                ->first();

            if ($unhealthyCheck) {
                $message = "Le check \"{$unhealthyCheck->check_type}\" est en état unhealthy depuis " . $unhealthyCheck->checked_at->diffForHumans() . ". Détails : " . ($unhealthyCheck->details ?? 'N/A');

                $this->error("🔥 Health check KO: {$tenant->name} — {$message}");
                $alertCount++;
                $emailAlerts[] = ['level' => 'danger', 'tenant' => $tenant->name, 'title' => 'Site inaccessible', 'message' => $message];

                if (!$dryRun) {
                    foreach ($admins as $admin) {
                        Notification::make()
                            ->danger()
                            ->title("Site inaccessible : {$tenant->name}")
                            ->body($message)
                            ->icon('heroicon-o-signal-slash')
                            ->actions([
                                Action::make('view')
                                    ->label('Voir les health checks')
                                    ->url(route('filament.admin.resources.tenants.view', $tenant->id) . '?activeRelationManager=1')
                                    ->button(),
                            ])
                            ->sendToDatabase($admin);
                    }
                }
            }

            // --- 6. Aucun backup depuis plus de 7 jours ---
            $lastBackup = TenantBackup::where('tenant_id', $tenant->id)
                ->where('status', 'completed')
                ->orderByDesc('created_at')
                ->first();

            $daysSinceBackup = $lastBackup
                ? (int) $lastBackup->created_at->diffInDays(now())
                : null;

            if ($daysSinceBackup === null || $daysSinceBackup > 7) {
                $message = $lastBackup
                    ? "Dernier backup réussi il y a {$daysSinceBackup} jours ({$lastBackup->created_at->format('d/m/Y')})."
                    : "Aucun backup réussi trouvé pour ce tenant.";

                $this->warn("💾 Backup absent: {$tenant->name} — {$message}");
                $alertCount++;
                $emailAlerts[] = ['level' => 'warning', 'tenant' => $tenant->name, 'title' => 'Backup manquant', 'message' => $message];

                if (!$dryRun) {
                    foreach ($admins as $admin) {
                        Notification::make()
                            ->warning()
                            ->title("Backup manquant : {$tenant->name}")
                            ->body($message)
                            ->icon('heroicon-o-archive-box-x-mark')
                            ->actions([
                                Action::make('backup')
                                    ->label('Voir les backups')
                                    ->url(route('filament.admin.resources.tenants.view', $tenant->id) . '?activeRelationManager=2')
                                    ->button(),
                            ])
                            ->sendToDatabase($admin);
                    }
                }
            }
        }

        // Envoi email récapitulatif si des alertes existent
        if ($alertCount > 0 && !$dryRun && !$this->option('no-email')) {
            $this->sendEmailSummary($emailAlerts, $admins);
        }

        if ($alertCount === 0) {
            $this->info('✅ Aucune alerte à envoyer.');
        } else {
            $mode = $dryRun ? '(dry-run — non envoyées)' : 'envoyées';
            $emailStatus = (!$dryRun && !$this->option('no-email')) ? ' + email récapitulatif' : '';
            $this->info("📬 {$alertCount} alerte(s) {$mode} à {$admins->count()} admin(s){$emailStatus}.");
        }

        return 0;
    }

    private function buildQuotaDetails(Tenant $tenant): string
    {
        $exceeded = [];

        if ($tenant->current_users > $tenant->max_users) {
            $exceeded[] = "utilisateurs ({$tenant->current_users}/{$tenant->max_users})";
        }
        if ($tenant->current_staff > $tenant->max_staff) {
            $exceeded[] = "personnel ({$tenant->current_staff}/{$tenant->max_staff})";
        }
        if ($tenant->current_students > $tenant->max_students) {
            $exceeded[] = "étudiants ({$tenant->current_students}/{$tenant->max_students})";
        }
        if ($tenant->current_inscriptions_per_year > $tenant->max_inscriptions_per_year) {
            $exceeded[] = "inscriptions ({$tenant->current_inscriptions_per_year}/{$tenant->max_inscriptions_per_year})";
        }
        if ($tenant->current_storage_mb > $tenant->max_storage_mb) {
            $exceeded[] = "stockage ({$tenant->current_storage_mb}/{$tenant->max_storage_mb} MB)";
        }

        return 'Limites dépassées : ' . implode(', ', $exceeded) . '.';
    }

    /**
     * Envoie un email récapitulatif de toutes les alertes aux admins.
     */
    private function sendEmailSummary(array $alerts, $admins): void
    {
        if (empty($alerts)) {
            return;
        }

        $recipientEmails = config('klassci.alert_emails', []);

        // Fallback : emails des admins actifs
        if (empty($recipientEmails)) {
            $recipientEmails = $admins->pluck('email')->filter()->toArray();
        }

        if (empty($recipientEmails)) {
            $this->warn('Aucune adresse email configurée pour les alertes.');
            return;
        }

        $date = now()->format('d/m/Y H:i');
        $count = count($alerts);

        $body = "Rapport d'alertes KLASSCI — {$date}\n";
        $body .= str_repeat('─', 50) . "\n\n";
        $body .= "{$count} alerte(s) détectée(s) :\n\n";

        foreach ($alerts as $alert) {
            $icon = match ($alert['level']) {
                'danger' => '🔴',
                'warning' => '⚠️',
                'info' => 'ℹ️',
                default => '•',
            };
            $body .= "{$icon} [{$alert['tenant']}] {$alert['title']}\n";
            $body .= "   {$alert['message']}\n\n";
        }

        $body .= str_repeat('─', 50) . "\n";
        $body .= "Panel admin : " . config('app.url', 'https://admin.klassci.com') . "/admin\n";

        try {
            Mail::raw($body, function ($message) use ($recipientEmails, $count, $date) {
                $message->to($recipientEmails)
                    ->subject("[KLASSCI Admin] {$count} alerte(s) — {$date}");
            });

            $this->info("📧 Email récapitulatif envoyé à : " . implode(', ', $recipientEmails));
        } catch (\Exception $e) {
            Log::error('Échec envoi email alertes KLASSCI', ['error' => $e->getMessage()]);
            $this->error("❌ Échec envoi email : {$e->getMessage()}");
        }
    }
}
