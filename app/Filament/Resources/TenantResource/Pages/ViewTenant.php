<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;

class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

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
                ->modalHeading('Déployer le tenant')
                ->modalDescription('Cette action va déployer les dernières mises à jour depuis GitHub. Cette opération peut prendre plusieurs minutes.')
                ->action(function () {
                    try {
                        \Artisan::call('tenant:deploy', [
                            'tenant' => $this->record->code,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Déploiement démarré')
                            ->body('Le déploiement a été lancé avec succès. Consultez l\'onglet Deployments pour suivre la progression.')
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
