<?php

namespace App\Filament\Group\Resources;

use App\Enums\AlertSeverity;
use App\Filament\Group\Resources\EstablishmentResource\Pages;
use App\Models\Tenant;
use App\Services\TenantAggregationService;
use App\Support\EtatMesure;
use App\Support\FcfaFormatter;
use App\Support\QuotaHealth;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class EstablishmentResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Établissements';

    protected static ?string $modelLabel = 'Établissement';

    protected static ?string $pluralModelLabel = 'Établissements';

    protected static ?int $navigationSort = 2;

    /**
     * Must stay <= TenantAggregationService::CACHE_TTL_HEALTH (300s) so the badge
     * never shows data staler than the service-level cache that backs it.
     */
    private const ALERTS_CACHE_TTL = 300;

    private const ALERTS_CACHE_PREFIX = 'group:alerts_v1:';

    /** @var array<int, list<array<string,mixed>>> per-request memo on top of Cache::remember — kills double Cache driver hit from getNavigationBadge() + getNavigationBadgeColor() */
    private static array $alertsRequestMemo = [];

    /**
     * Mémo des KPI d'un établissement — trois colonnes du tableau les lisent.
     *
     * La clé porte la période : `getTenantKpis()` en accepte une, et une clé
     * réduite au seul id de tenant servirait les chiffres d'une période sous
     * une autre le jour où ce tableau deviendra période-aware.
     *
     * Statique, donc partagé par tout le processus : `forgetKpisMemo()` existe
     * pour que les tests d'un même worker Pest ne se contaminent pas.
     *
     * @var array<string, array<string,mixed>>
     */
    private static array $kpisRequestMemo = [];

    /** Vide le mémo — appelé par les tests, jamais nécessaire en requête web. */
    public static function forgetKpisMemo(): void
    {
        self::$kpisRequestMemo = [];
    }

    /**
     * Filament calls this twice per sidebar render (badge count + color) plus
     * once per Livewire poll and per 60s notification poll. Direct service hits
     * are an N+1 bomb — we layer a 5-min cross-request cache AND a per-request
     * memo so a single page render = at most 1 cache driver round-trip.
     *
     * @return list<array<string,mixed>>
     */
    private static function currentGroupAlerts(): array
    {
        $group = auth('group')->user()?->group;
        if (! $group) {
            return [];
        }

        return self::$alertsRequestMemo[$group->id] ??= Cache::remember(
            self::ALERTS_CACHE_PREFIX . $group->id,
            self::ALERTS_CACHE_TTL,
            static function () use ($group): array {
                try {
                    return app(TenantAggregationService::class)
                        ->getGroupHealthMetrics($group)['alerts'] ?? [];
                } catch (\Throwable) {
                    return [];
                }
            }
        );
    }

    public static function forgetAlertsCache(int $groupId): void
    {
        Cache::forget(self::ALERTS_CACHE_PREFIX . $groupId);
        unset(self::$alertsRequestMemo[$groupId]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = count(self::currentGroupAlerts());

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Le badge compte les ALERTES, pas les établissements.
     *
     * Sans cette infobulle, une pastille « 3 » collée au libellé
     * « Établissements » se lit comme « 3 établissements » — et le groupe en
     * compte quatre. Un chiffre au mauvais endroit dit une chose fausse même
     * quand il est juste.
     */
    public static function getNavigationBadgeTooltip(): ?string
    {
        $count = count(self::currentGroupAlerts());

        return $count > 0
            ? $count . ' ' . \Illuminate\Support\Str::plural('alerte', $count) . ' en cours sur vos établissements'
            : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $alerts = self::currentGroupAlerts();
        if (empty($alerts)) {
            return null;
        }

        foreach ($alerts as $alert) {
            if (\App\Support\Alerts\AlertPayload::from($alert)->severity === AlertSeverity::Critical) {
                return 'danger';
            }
        }

        return 'warning';
    }

    /**
     * Les indicateurs d'un établissement, une seule fois par ligne rendue.
     *
     * Trois colonnes (étudiants, personnel, année) appelaient chacune
     * `getTenantKpis()` : quatre écoles = douze allers-retours de cache pour
     * un tableau de quatre lignes. Le service cache déjà, mais le mémo local
     * évite le trajet inutile — et garantit surtout que les trois colonnes
     * d'une même ligne montrent le MÊME instantané.
     *
     * @return array<string,mixed>
     */
    private static function kpis(Tenant $tenant): array
    {
        $periode = \App\Support\Period\PeriodFactory::default();
        $cle = $tenant->id . '|' . $periode->cacheKey();

        return self::$kpisRequestMemo[$cle] ??= app(TenantAggregationService::class)->getTenantKpis($tenant);
    }

    /**
     * La valeur d'un indicateur, ou le tiret si elle n'a pas été mesurée.
     *
     * Ces colonnes affichaient `?? 0`. Un `0` sous « Étudiants » pour une
     * école qui en compte deux mille, parce que sa base n'a pas répondu, est
     * plus grave qu'une case vide : il se lit comme une mesure.
     */
    private static function mesure(Tenant $tenant, string $cle, string $cleEtat): string
    {
        $kpis = self::kpis($tenant);

        return EtatMesure::aUneValeur($kpis[$cleEtat] ?? null)
            ? (string) ($kpis[$cle] ?? 0)
            : EtatMesure::TIRET;
    }

    /**
     * Comme `mesure()`, mais quand la valeur demande un formatage (pourcentage,
     * montant). Le formateur n'est appelé que si la mesure existe — sinon on
     * formaterait un zéro qu'on refuse justement d'afficher.
     *
     * @param  callable(array<string,mixed>): string  $formateur
     */
    private static function mesureFormatee(Tenant $tenant, string $cleEtat, callable $formateur): string
    {
        $kpis = self::kpis($tenant);

        return EtatMesure::aUneValeur($kpis[$cleEtat] ?? null) ? $formateur($kpis) : EtatMesure::TIRET;
    }

    /** Gris pour un chiffre non mesuré, couleur par défaut sinon. */
    private static function tonMesure(Tenant $tenant, string $cleEtat): ?string
    {
        return EtatMesure::aUneValeur(self::kpis($tenant)[$cleEtat] ?? null) ? null : 'gray';
    }

    /** Pourquoi il n'y a pas de chiffre — affiché au survol, jamais inventé. */
    private static function motifMesure(Tenant $tenant, string $cleEtat): ?string
    {
        $kpis = self::kpis($tenant);

        if (EtatMesure::aUneValeur($kpis[$cleEtat] ?? null)) {
            return null;
        }

        return EtatMesure::libelleMotif($kpis['motif'] ?? null);
    }

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
                    // Le test binaire `isOverLimit()` peignait en VERT une école a
                    // 690/700 pendant que le bandeau d'alertes du meme portail
                    // annoncait « Quota students a 98.6 % ». Les deux lisent
                    // desormais QuotaHealth.
                    ->color(fn (Tenant $record) => QuotaHealth::tone(QuotaHealth::percentage(
                        $record->current_inscriptions_per_year,
                        $record->max_inscriptions_per_year,
                    )))
                    ->tooltip(fn (Tenant $record) => QuotaHealth::percentage(
                        $record->current_inscriptions_per_year,
                        $record->max_inscriptions_per_year,
                    ) . ' % du quota souscrit'),

                // `students` et non `inscriptions` : le total du groupe affiche
                // en tete du tableau de bord somme les etudiants DISTINCTS
                // (`total_students` = Somme `students`). Cette colonne lisait
                // les LIGNES d'inscription — donc la somme de la colonne ne
                // pouvait pas egaler le total affiche au-dessus des qu'un
                // etudiant a deux inscriptions actives. Un seul sens par
                // libelle : « Etudiants » compte des personnes.
                Tables\Columns\TextColumn::make('live_students')
                    ->label('Étudiants')
                    ->getStateUsing(fn (Tenant $record) => self::mesure($record, 'students', 'etat_effectifs'))
                    ->color(fn (Tenant $record) => self::tonMesure($record, 'etat_effectifs'))
                    ->tooltip(fn (Tenant $record) => self::motifMesure($record, 'etat_effectifs')),

                Tables\Columns\TextColumn::make('live_staff')
                    ->label('Personnel')
                    ->getStateUsing(fn (Tenant $record) => self::mesure($record, 'staff', 'etat_personnel'))
                    ->color(fn (Tenant $record) => self::tonMesure($record, 'etat_personnel'))
                    ->tooltip(fn (Tenant $record) => self::motifMesure($record, 'etat_personnel')),

                Tables\Columns\TextColumn::make('academic_year')
                    ->label('Année')
                    // « N/A » ne dit rien : ni si l'annee manque, ni si la base
                    // est muette. Le tiret + l'infobulle disent laquelle des deux.
                    ->getStateUsing(function (Tenant $record) {
                        $annee = self::kpis($record)['academic_year'] ?? null;

                        return ($annee === null || $annee === 'N/A') ? EtatMesure::TIRET : $annee;
                    })
                    ->color(fn (Tenant $record) => self::tonMesure($record, 'etat_effectifs'))
                    ->tooltip(fn (Tenant $record) => self::motifMesure($record, 'etat_effectifs')),

                // Cette colonne debordait de la page : « islg-rostan.klassci.co »,
                // « rostan-yopougon.klas ». Elle n'ajoutait pourtant rien — la
                // colonne Code montre deja le sous-domaine, et l'action « Ouvrir »
                // de la ligne mene au meme endroit. On la garde disponible, mais
                // repliee : le tableau tient enfin dans la largeur.
                Tables\Columns\TextColumn::make('subdomain')
                    ->label('URL')
                    ->formatStateUsing(fn (string $state) => "{$state}.klassci.com")
                    ->url(fn (Tenant $record) => "https://{$record->subdomain}.klassci.com", shouldOpenInNewTab: true)
                    ->color('primary')
                    ->toggleable(isToggledHiddenByDefault: true),
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

                // Ces compteurs viennent de klassci_master, pas de la base de
                // l'ecole : ils survivent a une panne et servent au paywall.
                //
                // Mais ils NE MESURENT PAS la meme chose que la section « Donnees
                // en temps reel » juste en dessous : `current_students` compte
                // les etudiants AYANT UN COMPTE plateforme, la ou l'indicateur
                // compte les inscriptions actives de l'annee. La fiche affichait
                // donc « Etudiants 620 / 800 » et « Etudiants inscrits — » a
                // quelques centimetres, sans jamais dire que les deux ne parlent
                // pas de la meme population.
                Infolists\Components\Section::make('Quotas & Usage')
                    ->description(fn (Tenant $record) => 'Compteurs d\'abonnement, relevés par KLASSCI'
                        . ($record->stats_measured_at
                            ? ' — dernier relevé ' . $record->stats_measured_at->locale('fr')->diffForHumans()
                            : ' — jamais relevés'))
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('current_inscriptions_per_year')
                            ->label('Inscriptions')
                            ->suffix(fn (Tenant $record) => " / {$record->max_inscriptions_per_year}")
                            ->color(fn (Tenant $record) => QuotaHealth::tone(QuotaHealth::percentage(
                                $record->current_inscriptions_per_year,
                                $record->max_inscriptions_per_year,
                            ))),
                        Infolists\Components\TextEntry::make('current_students')
                            ->label('Étudiants avec un compte')
                            ->suffix(fn (Tenant $record) => " / {$record->max_students}")
                            ->helperText('Comptes plateforme — distinct des inscriptions actives de l\'année')
                            ->color(fn (Tenant $record) => QuotaHealth::tone(QuotaHealth::percentage(
                                $record->current_students,
                                $record->max_students,
                            ))),
                        Infolists\Components\TextEntry::make('current_staff')
                            ->label('Personnel')
                            ->suffix(fn (Tenant $record) => " / {$record->max_staff}")
                            ->color(fn (Tenant $record) => QuotaHealth::tone(QuotaHealth::percentage(
                                $record->current_staff,
                                $record->max_staff,
                            ))),
                    ]),

                // Six entrées lisaient chacune `getTenantKpis()` et repliaient sur
                // `?? 0` : une fiche d'école injoignable affichait « 0 étudiant,
                // 0 % de recouvrement, 0 FCFA encaissés », soit le portrait d'une
                // école morte. Elles partagent maintenant un seul instantané et
                // disent le tiret quand il n'y a rien à dire.
                Infolists\Components\Section::make('Données en temps réel')
                    ->description(fn (Tenant $record) => EtatMesure::aUneValeur(self::kpis($record)['etat_finances'] ?? null)
                        ? null
                        : EtatMesure::libelleMotif(self::kpis($record)['motif'] ?? null))
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('live_inscriptions')
                            ->label('Étudiants inscrits')
                            ->getStateUsing(fn (Tenant $record) => self::mesure($record, 'students', 'etat_effectifs'))
                            ->color(fn (Tenant $record) => self::tonMesure($record, 'etat_effectifs')),
                        Infolists\Components\TextEntry::make('live_staff')
                            ->label('Personnel')
                            ->getStateUsing(fn (Tenant $record) => self::mesure($record, 'staff', 'etat_personnel'))
                            ->color(fn (Tenant $record) => self::tonMesure($record, 'etat_personnel')),
                        Infolists\Components\TextEntry::make('live_collection_rate')
                            ->label('Taux de recouvrement')
                            ->getStateUsing(fn (Tenant $record) => self::mesureFormatee(
                                $record,
                                'etat_finances',
                                fn (array $k) => ($k['collection_rate'] ?? 0) . ' %',
                            ))
                            ->color(fn (Tenant $record) => self::tonMesure($record, 'etat_finances')),
                        Infolists\Components\TextEntry::make('live_revenue_expected')
                            ->label('Revenus attendus')
                            ->getStateUsing(fn (Tenant $record) => self::mesureFormatee(
                                $record,
                                'etat_finances',
                                fn (array $k) => FcfaFormatter::full((float) ($k['revenue_expected'] ?? 0)) . ' FCFA',
                            ))
                            ->color(fn (Tenant $record) => self::tonMesure($record, 'etat_finances')),
                        Infolists\Components\TextEntry::make('live_revenue_collected')
                            ->label('Revenus encaissés')
                            ->getStateUsing(fn (Tenant $record) => self::mesureFormatee(
                                $record,
                                'etat_finances',
                                fn (array $k) => FcfaFormatter::full((float) ($k['revenue_collected'] ?? 0)) . ' FCFA',
                            ))
                            ->color(fn (Tenant $record) => self::tonMesure($record, 'etat_finances')),
                        Infolists\Components\TextEntry::make('live_academic_year')
                            ->label('Année universitaire')
                            ->getStateUsing(function (Tenant $record) {
                                $annee = self::kpis($record)['academic_year'] ?? null;

                                return ($annee === null || $annee === 'N/A') ? EtatMesure::TIRET : $annee;
                            })
                            ->color(fn (Tenant $record) => self::tonMesure($record, 'etat_effectifs')),
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
