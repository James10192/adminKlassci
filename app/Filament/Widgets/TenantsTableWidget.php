<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TenantsTableWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Établissements Nécessitant une Attention')
            ->query(
                Tenant::query()
                    ->where('status', 'active')
                    ->where(function ($query) {
                        // Tenants avec quotas dépassés
                        $query->whereRaw('current_users > max_users')
                            ->orWhereRaw('current_staff > max_staff')
                            ->orWhereRaw('current_students > max_students')
                            ->orWhereRaw('current_storage_mb > max_storage_mb')
                            // Ou abonnement expirant dans 30 jours
                            ->orWhere(function ($q) {
                                $q->where('subscription_end_date', '<=', now()->addDays(30))
                                  ->where('subscription_end_date', '>=', now());
                            });
                    })
                    ->orderByRaw('CASE
                        WHEN current_users > max_users THEN 1
                        WHEN current_staff > max_staff THEN 2
                        WHEN current_students > max_students THEN 3
                        ELSE 4
                    END')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Établissement')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('plan')
                    ->label('Plan')
                    ->colors([
                        'secondary' => 'free',
                        'primary' => 'essentiel',
                        'success' => 'professional',
                        'warning' => 'elite',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'free' => 'Free',
                        'essentiel' => 'Essentiel',
                        'professional' => 'Professional',
                        'elite' => 'Elite',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('current_users')
                    ->label('Utilisateurs')
                    ->formatStateUsing(fn (Tenant $record): string =>
                        "{$record->current_users}/{$record->max_users}"
                    )
                    ->badge()
                    ->color(fn (Tenant $record): string =>
                        $record->current_users > $record->max_users ? 'danger' : 'success'
                    ),

                Tables\Columns\TextColumn::make('current_students')
                    ->label('Étudiants')
                    ->formatStateUsing(fn (Tenant $record): string =>
                        "{$record->current_students}/{$record->max_students}"
                    )
                    ->badge()
                    ->color(fn (Tenant $record): string =>
                        $record->current_students > $record->max_students ? 'danger' : 'success'
                    ),

                Tables\Columns\TextColumn::make('subscription_end_date')
                    ->label('Expiration')
                    ->date('d/m/Y')
                    ->badge()
                    ->color(fn ($state) => !$state ? 'gray' : (now()->diffInDays($state, false) < 0 ? 'danger' : (now()->diffInDays($state, false) <= 30 ? 'warning' : 'success')))
                    ->formatStateUsing(function ($state) {
                        if (!$state) return 'N/A';
                        $daysUntil = now()->diffInDays($state, false);
                        if ($daysUntil < 0) return 'Expiré';
                        return $daysUntil <= 30 ? "Dans {$daysUntil}j" : $state->format('d/m/Y');
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Voir')
                    ->icon('heroicon-m-eye')
                    ->url(fn (Tenant $record): string => route('filament.admin.resources.tenants.edit', $record)),
            ])
            ->paginated([5, 10, 25]);
    }
}
