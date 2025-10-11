<?php

namespace App\Filament\Resources\TenantDeploymentResource\Pages;

use App\Filament\Resources\TenantDeploymentResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class ViewTenantDeployment extends ViewRecord
{
    protected static string $resource = TenantDeploymentResource::class;

    protected static ?string $title = 'Détails du déploiement';

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Informations générales')
                    ->schema([
                        Infolists\Components\TextEntry::make('tenant.name')
                            ->label('Tenant'),

                        Infolists\Components\TextEntry::make('git_branch')
                            ->label('Branche Git')
                            ->badge()
                            ->color('info'),

                        Infolists\Components\TextEntry::make('git_commit_hash')
                            ->label('Commit Hash')
                            ->copyable()
                            ->copyMessage('Commit hash copié!')
                            ->copyMessageDuration(1500),

                        Infolists\Components\TextEntry::make('status')
                            ->label('Statut')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'in_progress' => 'warning',
                                'success' => 'success',
                                'failed' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'in_progress' => 'En cours',
                                'success' => 'Réussi',
                                'failed' => 'Échoué',
                                default => $state,
                            }),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Chronologie')
                    ->schema([
                        Infolists\Components\TextEntry::make('started_at')
                            ->label('Démarré le')
                            ->dateTime('d/m/Y H:i:s'),

                        Infolists\Components\TextEntry::make('completed_at')
                            ->label('Terminé le')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('En cours...'),

                        Infolists\Components\TextEntry::make('duration_seconds')
                            ->label('Durée')
                            ->formatStateUsing(fn ($state) => $state ? "{$state} secondes" : 'N/A')
                            ->suffix(' s'),

                        Infolists\Components\TextEntry::make('deployedBy.name')
                            ->label('Déployé par')
                            ->default('CLI'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Erreur')
                    ->schema([
                        Infolists\Components\TextEntry::make('error_message')
                            ->label('Message d\'erreur')
                            ->color('danger')
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('error_details')
                            ->label('Détails de l\'erreur')
                            ->markdown()
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record->status === 'failed')
                    ->collapsible(),

                Infolists\Components\Section::make('Logs de déploiement')
                    ->schema([
                        Infolists\Components\TextEntry::make('deployment_log')
                            ->label('')
                            ->markdown()
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(false),
            ]);
    }
}
