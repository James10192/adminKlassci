<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TenantsWithIssues extends BaseWidget
{
    protected static ?int $sort = 2;
    protected int | string | array $columnSpan = 'full';

    // Rafraîchissement automatique toutes les 30 secondes
    protected static ?string $pollingInterval = '30s';

    protected function getTableHeading(): ?string
    {
        return '🚨 Tenants nécessitant attention';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // Récupérer uniquement les tenants avec des checks warning/critical récents (< 10 min)
                TenantHealthCheck::query()
                    ->whereIn('status', ['degraded', 'unhealthy'])
                    ->where('created_at', '>=', now()->subMinutes(10))
                    ->with(['tenant'])
                    ->orderBy('status', 'desc') // critical en premier
                    ->orderBy('created_at', 'desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => route('filament.admin.resources.tenants.view', $record->tenant_id))
                    ->icon('heroicon-o-building-office-2')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('check_type')
                    ->label('Type de Check')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'http_status' => 'HTTP Status',
                        'database_connection' => 'Database',
                        'disk_space' => 'Disk Space',
                        'ssl_certificate' => 'SSL Certificate',
                        'application_errors' => 'App Errors',
                        'queue_workers' => 'Queue Workers',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'http_status' => 'info',
                        'database_connection' => 'primary',
                        'disk_space' => 'warning',
                        'ssl_certificate' => 'success',
                        'application_errors' => 'danger',
                        'queue_workers' => 'secondary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'unhealthy' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'healthy' => 'heroicon-o-check-circle',
                        'degraded' => 'heroicon-o-exclamation-triangle',
                        'unhealthy' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-question-mark-circle',
                    }),

                Tables\Columns\TextColumn::make('details')
                    ->label('Détails')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->details)
                    ->wrap(),

                Tables\Columns\TextColumn::make('response_time_ms')
                    ->label('Temps de réponse')
                    ->formatStateUsing(fn ($state) => $state ? $state . ' ms' : 'N/A')
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state < 500 => 'success',
                        $state < 1000 => 'warning',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('checked_at')
                    ->label('Vérifié')
                    ->dateTime('d/m/Y H:i')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('rerun')
                    ->label('Re-vérifier')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function ($record) {
                        \Artisan::call('tenant:health-check', [
                            'tenant' => $record->tenant->code,
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Health Check relancé')
                            ->body("Health check exécuté pour {$record->tenant->name}")
                            ->send();
                    }),

                Tables\Actions\Action::make('view')
                    ->label('Voir tenant')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => route('filament.admin.resources.tenants.view', $record->tenant_id)),
            ])
            ->emptyStateHeading('🎉 Aucun problème détecté')
            ->emptyStateDescription('Tous les tenants sont en bonne santé')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
