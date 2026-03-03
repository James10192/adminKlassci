<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Notifications\Actions\Action;
use Illuminate\Console\Command;

class SendTenantAlerts extends Command
{
    protected $signature = 'tenant:send-alerts
                            {--dry-run : Affiche les alertes sans les envoyer}';

    protected $description = 'Envoie des notifications aux admins SaaS pour les quotas dépassés, abonnements expirants et tenants inactifs.';

    public function handle(): int
    {
        $admins = User::where('is_active', true)->get();

        if ($admins->isEmpty()) {
            $this->warn('Aucun admin actif trouvé.');
            return 0;
        }

        $dryRun = $this->option('dry-run');
        $alertCount = 0;

        $activeTenants = Tenant::where('status', 'active')->get();

        foreach ($activeTenants as $tenant) {
            // --- 1. Quota dépassé ---
            if ($tenant->isOverQuota()) {
                $quotaDetails = $this->buildQuotaDetails($tenant);

                $this->info("🔴 Quota dépassé: {$tenant->name} — {$quotaDetails}");
                $alertCount++;

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

            // --- 2. Abonnement expirant dans ≤ 30 jours ---
            if ($tenant->subscription_end_date) {
                $daysUntilExpiry = now()->diffInDays($tenant->subscription_end_date, false);

                if ($daysUntilExpiry >= 0 && $daysUntilExpiry <= 30) {
                    $expiryDate = $tenant->subscription_end_date->format('d/m/Y');
                    $message = "L'abonnement expire dans {$daysUntilExpiry} jour(s) ({$expiryDate}).";

                    $this->warn("⚠️  Expiration proche: {$tenant->name} — {$message}");
                    $alertCount++;

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

            // --- 3. Tenant inactif depuis 7 jours (aucune mise à jour des stats) ---
            $daysSinceUpdate = $tenant->updated_at
                ? now()->diffInDays($tenant->updated_at, false)
                : null;

            // updated_at devient négatif (passé) → diffInDays avec false retourne négatif si dans le passé
            $daysSinceUpdate = $tenant->updated_at
                ? now()->diffInDays($tenant->updated_at)
                : null;

            if ($daysSinceUpdate !== null && $daysSinceUpdate >= 7) {
                $lastUpdate = $tenant->updated_at->diffForHumans();
                $message = "Aucune activité détectée depuis {$daysSinceUpdate} jours (dernière mise à jour : {$lastUpdate}).";

                $this->line("💤 Inactif: {$tenant->name} — {$message}");
                $alertCount++;

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
        }

        if ($alertCount === 0) {
            $this->info('✅ Aucune alerte à envoyer.');
        } else {
            $mode = $dryRun ? '(dry-run — non envoyées)' : 'envoyées';
            $this->info("📬 {$alertCount} alerte(s) {$mode} à {$admins->count()} admin(s).");
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
}
