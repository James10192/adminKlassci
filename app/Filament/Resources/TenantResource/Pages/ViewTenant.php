<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;

class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

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

        return implode(' · ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [
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
                ->visible(fn () => $this->record->status === 'active'),

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
                ->visible(fn () => $this->record->status === 'active'),

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
                ->visible(fn () => $this->record->status === 'active'),

            // Action standard: Edit
            Actions\EditAction::make()
                ->icon('heroicon-o-pencil')
                ->color('gray'),
        ];
    }
}
