<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

class ViewTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * Mode édition inline : false = lecture seule, true = édition active
     */
    public bool $isEditing = false;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        $parts = [];

        $parts[] = strtoupper($this->record->code);

        $statusLabel = match ($this->record->status) {
            'active' => '● Actif',
            'suspended' => '⏸ Suspendu',
            'inactive' => '○ Inactif',
            default => $this->record->status,
        };
        $parts[] = $statusLabel;

        if ($this->record->plan) {
            $parts[] = ucfirst($this->record->plan);
        }

        if ($this->record->subscription_end_date) {
            $days = now()->diffInDays($this->record->subscription_end_date, false);
            if ($days < 0) {
                $parts[] = 'Abonnement expiré';
            } elseif ($days <= 30) {
                $parts[] = "Expire dans {$days}j";
            }
        }

        if ($this->isEditing) {
            $parts[] = '✏️ Mode édition';
        }

        return implode(' · ', $parts);
    }

    /**
     * Après sauvegarde réussie, repasser en mode lecture et invalider le cache limits du tenant
     */
    protected function afterSave(): void
    {
        $this->isEditing = false;

        // Invalider le cache "paywall_limits_{code}" du tenant pour que le compte à rebours
        // se mette à jour immédiatement (sans attendre l'expiration naturelle des 5 min)
        try {
            \Artisan::call('tenant:clear-limits-cache', [
                'tenant' => $this->record->code,
            ]);
        } catch (\Exception $e) {
            // Non bloquant — le cache expirera naturellement après 5 min
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // ── Actions visibles en MODE LECTURE ──────────────────────────────

            // Action: Mettre à jour les stats
            Actions\Action::make('update_stats')
                ->label('Mettre à jour les stats')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Mettre à jour les statistiques')
                ->modalDescription('Cette action va recalculer les statistiques d\'utilisation (utilisateurs, étudiants, personnel, stockage) depuis la base de données du tenant.')
                ->action(function () {
                    try {
                        $exitCode = \Artisan::call('tenant:update-stats', [
                            'tenant' => $this->record->code,
                        ]);

                        $output = \Artisan::output();

                        if ($exitCode !== 0 || str_contains($output, '❌') || str_contains($output, 'Erreur')) {
                            Notification::make()
                                ->danger()
                                ->title('Erreur de mise à jour')
                                ->body('La mise à jour a échoué. Vérifiez les credentials de la base de données.')
                                ->persistent()
                                ->send();
                            return;
                        }

                        $this->record->refresh();

                        Notification::make()
                            ->success()
                            ->title('Statistiques mises à jour')
                            ->body('Les statistiques ont été mises à jour avec succès.')
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Erreur')
                            ->body("Erreur : {$e->getMessage()}")
                            ->persistent()
                            ->send();
                    }
                })
                ->visible(fn () => ! $this->isEditing && $this->record->status === 'active'),

            // Action: Health Check
            Actions\Action::make('health_check')
                ->label('Health Check')
                ->icon('heroicon-o-heart')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Exécuter Health Check')
                ->modalDescription('Cette action va exécuter toutes les vérifications de santé (HTTP, Database, SSL, Storage, etc.).')
                ->action(function () {
                    try {
                        $exitCode = \Artisan::call('tenant:health-check', [
                            'tenant' => $this->record->code,
                        ]);

                        $output = \Artisan::output();

                        if ($exitCode !== 0 || str_contains($output, '❌') || str_contains($output, 'CRITIQUE')) {
                            Notification::make()
                                ->warning()
                                ->title('Health Check terminé avec des problèmes')
                                ->body('Certaines vérifications ont échoué. Consultez l\'onglet Health Checks pour plus de détails.')
                                ->persistent()
                                ->send();
                        } else {
                            Notification::make()
                                ->success()
                                ->title('Health Check réussi')
                                ->body('Toutes les vérifications ont été effectuées avec succès.')
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Erreur')
                            ->body("Erreur : {$e->getMessage()}")
                            ->persistent()
                            ->send();
                    }
                })
                ->visible(fn () => ! $this->isEditing && $this->record->status === 'active'),

            // Action: Déployer
            Actions\Action::make('deploy')
                ->label('Déployer')
                ->icon('heroicon-o-rocket-launch')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(fn () => "Déployer — {$this->record->name}")
                ->modalDescription(function () {
                    $branch = $this->record->git_branch ?? 'presentation';
                    $lastDeploy = $this->record->last_deployed_at
                        ? $this->record->last_deployed_at->diffForHumans()
                        : 'jamais';
                    return "Branche : « {$branch} ». Dernier déploiement : {$lastDeploy}. "
                        . "Le site sera mis en maintenance pendant ~2-5 min.";
                })
                ->modalSubmitActionLabel('Lancer le déploiement')
                ->action(function () {
                    try {
                        $exitCode = \Artisan::call('tenant:deploy', [
                            'tenant' => $this->record->code,
                            '--skip-backup' => true,
                        ]);

                        $output = \Artisan::output();

                        if ($exitCode !== 0 || str_contains($output, '❌') || str_contains($output, 'Erreur')) {
                            Notification::make()
                                ->danger()
                                ->title('Déploiement échoué')
                                ->body('Le déploiement a échoué. Consultez l\'onglet Deployments pour les détails.')
                                ->persistent()
                                ->send();
                            return;
                        }

                        $this->record->refresh();

                        Notification::make()
                            ->success()
                            ->title('Déploiement réussi ✅')
                            ->body("Le tenant « {$this->record->name} » a été mis à jour avec succès.")
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Erreur de déploiement')
                            ->body("Erreur : {$e->getMessage()}")
                            ->persistent()
                            ->send();
                    }
                })
                ->visible(fn () => ! $this->isEditing && $this->record->status === 'active'),

            // Action: Générer / Régénérer le token API
            Actions\Action::make('generate_api_token')
                ->label(fn () => $this->record->api_token ? 'Régénérer le token API' : 'Générer le token API')
                ->icon('heroicon-o-key')
                ->color(fn () => $this->record->api_token ? 'warning' : 'success')
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->record->api_token ? 'Régénérer le token API' : 'Générer le token API')
                ->modalDescription(fn () => $this->record->api_token
                    ? 'Un nouveau token sera généré. L\'ancien token sera immédiatement invalidé et le tenant ne pourra plus appeler l\'API Master tant que son .env n\'est pas mis à jour.'
                    : 'Un token sécurisé sera créé pour ce tenant. Il devra être copié dans le fichier .env du tenant.')
                ->modalSubmitActionLabel('Générer')
                ->action(function () {
                    $token = bin2hex(random_bytes(32)); // 64 caractères hex

                    $this->record->update([
                        'api_token'            => $token,
                        'api_token_created_at' => now(),
                    ]);

                    $this->record->refresh();

                    $appUrl   = config('app.url');
                    $envBlock = implode("\n", [
                        "# Coller ces lignes dans le fichier .env du tenant ({$this->record->code})",
                        "MASTER_API_URL={$appUrl}/api",
                        "MASTER_API_TOKEN={$token}",
                        "TENANT_CODE={$this->record->code}",
                    ]);

                    // Tenter la mise à jour automatique du .env du tenant sur le serveur
                    $autoApplied = false;
                    try {
                        $exitCode = \Artisan::call('tenant:configure-env', [
                            'tenant' => $this->record->code,
                        ]);
                        $autoApplied = ($exitCode === 0);
                    } catch (\Exception $e) {
                        // Non bloquant — le bloc .env reste affiché pour copie manuelle
                    }

                    if ($autoApplied) {
                        Notification::make()
                            ->success()
                            ->title('Token API généré et appliqué ✅')
                            ->body("Le .env du tenant a été mis à jour automatiquement. Cache config vidé.")
                            ->persistent()
                            ->send();
                    } else {
                        Notification::make()
                            ->success()
                            ->title('Token API généré ✅')
                            ->body("Copiez ce bloc dans le .env du tenant :\n\n{$envBlock}")
                            ->persistent()
                            ->send();
                    }
                })
                ->visible(fn () => ! $this->isEditing),

            // Action: Détecter la branche Git
            Actions\Action::make('detect_branch')
                ->label('Détecter la branche')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Détecter la branche Git')
                ->modalDescription(function () {
                    $path = rtrim(env('PRODUCTION_PATH', ''), '/') . '/' . $this->record->code;
                    return "Cette action va exécuter « git branch --show-current » dans {$path} et mettre à jour la branche enregistrée en base.";
                })
                ->modalSubmitActionLabel('Détecter')
                ->action(function () {
                    $productionPath = rtrim(env('PRODUCTION_PATH', ''), '/');
                    if (!$productionPath) {
                        Notification::make()
                            ->danger()
                            ->title('PRODUCTION_PATH non défini')
                            ->body('La variable PRODUCTION_PATH n\'est pas configurée dans le .env admin.')
                            ->persistent()
                            ->send();
                        return;
                    }

                    $tenantPath = "{$productionPath}/{$this->record->code}";

                    if (!is_dir($tenantPath)) {
                        Notification::make()
                            ->danger()
                            ->title('Répertoire introuvable')
                            ->body("Le répertoire {$tenantPath} n'existe pas sur le serveur.")
                            ->persistent()
                            ->send();
                        return;
                    }

                    try {
                        $result = \Illuminate\Support\Facades\Process::path($tenantPath)
                            ->run('git branch --show-current');

                        $branch = trim($result->output());

                        if (!$result->successful() || empty($branch)) {
                            // Fallback : detached HEAD
                            $result2 = \Illuminate\Support\Facades\Process::path($tenantPath)
                                ->run('git rev-parse --abbrev-ref HEAD');
                            $branch = trim($result2->output());
                        }

                        if (empty($branch) || $branch === 'HEAD') {
                            Notification::make()
                                ->warning()
                                ->title('Branche non détectée')
                                ->body('Le dépôt est en detached HEAD. Vérifiez manuellement avec : git status')
                                ->persistent()
                                ->send();
                            return;
                        }

                        $oldBranch = $this->record->git_branch;
                        $this->record->update(['git_branch' => $branch]);
                        $this->record->refresh();

                        Notification::make()
                            ->success()
                            ->title('Branche détectée ✅')
                            ->body("Branche mise à jour : « {$oldBranch} » → « {$branch} »")
                            ->send();

                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title('Erreur')
                            ->body("Impossible de détecter la branche : {$e->getMessage()}")
                            ->persistent()
                            ->send();
                    }
                })
                ->visible(fn () => ! $this->isEditing),

            // ── Bouton Modifier (mode lecture) ────────────────────────────────

            Actions\Action::make('start_editing')
                ->label('Modifier')
                ->icon('heroicon-o-pencil')
                ->color('gray')
                ->action(fn () => $this->isEditing = true)
                ->visible(fn () => ! $this->isEditing),

            // ── Boutons Sauvegarder + Annuler (mode édition) ──────────────────

            Actions\Action::make('save_record')
                ->label('Sauvegarder')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action(function () {
                    $this->save(shouldRedirect: false);
                    $this->isEditing = false;
                })
                ->visible(fn () => $this->isEditing),

            Actions\Action::make('cancel_editing')
                ->label('Annuler')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->action(function () {
                    $this->isEditing = false;
                    $this->fillForm();
                })
                ->visible(fn () => $this->isEditing),
        ];
    }
}
