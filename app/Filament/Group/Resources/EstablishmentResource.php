<?php

namespace App\Filament\Group\Resources;

use App\Filament\Group\Resources\EstablishmentResource\Pages;
use App\Models\Tenant;
use App\Services\TenantAggregationService;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;

class EstablishmentResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Établissements';

    protected static ?string $modelLabel = 'Établissement';

    protected static ?string $pluralModelLabel = 'Établissements';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $groupId = auth('group')->user()?->group_id;

        return parent::getEloquentQuery()->where('group_id', $groupId);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        'maintenance' => 'info',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('plan')
                    ->label('Plan')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'elite' => 'success',
                        'professional' => 'primary',
                        'essentiel' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('current_inscriptions_per_year')
                    ->label('Inscriptions')
                    ->getStateUsing(fn (Tenant $record) => $record->current_inscriptions_per_year . ' / ' . $record->max_inscriptions_per_year)
                    ->color(fn (Tenant $record) => $record->isOverLimit('inscriptions') ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('live_students')
                    ->label('Étudiants')
                    ->getStateUsing(function (Tenant $record) {
                        $service = app(TenantAggregationService::class);
                        $kpis = $service->getTenantKpis($record);
                        return (string) ($kpis['inscriptions'] ?? 0);
                    }),

                Tables\Columns\TextColumn::make('live_staff')
                    ->label('Personnel')
                    ->getStateUsing(function (Tenant $record) {
                        $service = app(TenantAggregationService::class);
                        $kpis = $service->getTenantKpis($record);
                        return (string) ($kpis['staff'] ?? 0);
                    }),

                Tables\Columns\TextColumn::make('academic_year')
                    ->label('Année')
                    ->getStateUsing(function (Tenant $record) {
                        $service = app(TenantAggregationService::class);
                        $kpis = $service->getTenantKpis($record);
                        return $kpis['academic_year'] ?? 'N/A';
                    }),

                Tables\Columns\TextColumn::make('subdomain')
                    ->label('URL')
                    ->formatStateUsing(fn (string $state) => "{$state}.klassci.com")
                    ->url(fn (Tenant $record) => "https://{$record->subdomain}.klassci.com", shouldOpenInNewTab: true)
                    ->color('primary'),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('open')
                    ->label('Ouvrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Tenant $record) => "https://{$record->subdomain}.klassci.com", shouldOpenInNewTab: true)
                    ->color('primary'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Informations générales')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Nom'),
                        Infolists\Components\TextEntry::make('code')
                            ->label('Code')
                            ->badge(),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Statut')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'active' => 'success',
                                'suspended' => 'warning',
                                default => 'danger',
                            }),
                        Infolists\Components\TextEntry::make('plan')
                            ->label('Plan'),
                        Infolists\Components\TextEntry::make('admin_email')
                            ->label('Email admin'),
                        Infolists\Components\TextEntry::make('phone')
                            ->label('Téléphone'),
                    ]),

                Infolists\Components\Section::make('Quotas & Usage')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('current_inscriptions_per_year')
                            ->label('Inscriptions')
                            ->suffix(fn (Tenant $record) => " / {$record->max_inscriptions_per_year}"),
                        Infolists\Components\TextEntry::make('current_students')
                            ->label('Étudiants')
                            ->suffix(fn (Tenant $record) => " / {$record->max_students}"),
                        Infolists\Components\TextEntry::make('current_staff')
                            ->label('Personnel')
                            ->suffix(fn (Tenant $record) => " / {$record->max_staff}"),
                    ]),

                Infolists\Components\Section::make('Données en temps réel')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('live_inscriptions')
                            ->label('Étudiants inscrits')
                            ->getStateUsing(function (Tenant $record) {
                                $service = app(TenantAggregationService::class);
                                $kpis = $service->getTenantKpis($record);
                                return (string) ($kpis['inscriptions'] ?? 0);
                            }),
                        Infolists\Components\TextEntry::make('live_staff')
                            ->label('Personnel')
                            ->getStateUsing(function (Tenant $record) {
                                $service = app(TenantAggregationService::class);
                                $kpis = $service->getTenantKpis($record);
                                return (string) ($kpis['staff'] ?? 0);
                            }),
                        Infolists\Components\TextEntry::make('live_collection_rate')
                            ->label('Taux de recouvrement')
                            ->getStateUsing(function (Tenant $record) {
                                $service = app(TenantAggregationService::class);
                                $kpis = $service->getTenantKpis($record);
                                return ($kpis['collection_rate'] ?? 0) . ' %';
                            }),
                        Infolists\Components\TextEntry::make('live_revenue_expected')
                            ->label('Revenus attendus')
                            ->getStateUsing(function (Tenant $record) {
                                $service = app(TenantAggregationService::class);
                                $kpis = $service->getTenantKpis($record);
                                return number_format((float) ($kpis['revenue_expected'] ?? 0), 0, ',', ' ') . ' FCFA';
                            }),
                        Infolists\Components\TextEntry::make('live_revenue_collected')
                            ->label('Revenus encaissés')
                            ->getStateUsing(function (Tenant $record) {
                                $service = app(TenantAggregationService::class);
                                $kpis = $service->getTenantKpis($record);
                                return number_format((float) ($kpis['revenue_collected'] ?? 0), 0, ',', ' ') . ' FCFA';
                            }),
                        Infolists\Components\TextEntry::make('live_academic_year')
                            ->label('Année universitaire')
                            ->getStateUsing(function (Tenant $record) {
                                $service = app(TenantAggregationService::class);
                                $kpis = $service->getTenantKpis($record);
                                return $kpis['academic_year'] ?? 'N/A';
                            }),
                    ]),

                Infolists\Components\Section::make('Abonnement')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('monthly_fee')
                            ->label('Mensualité')
                            ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' FCFA'),
                        Infolists\Components\TextEntry::make('subscription_start_date')
                            ->label('Début')
                            ->formatStateUsing(fn ($state) => $state ? $state->format('d/m/Y') : '—'),
                        Infolists\Components\TextEntry::make('subscription_end_date')
                            ->label('Fin')
                            ->formatStateUsing(fn ($state) => $state ? $state->format('d/m/Y') : '—')
                            ->color(fn (Tenant $record) => $record->is_expired ? 'danger' : 'success'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEstablishments::route('/'),
            'view' => Pages\ViewEstablishment::route('/{record}'),
        ];
    }
}
