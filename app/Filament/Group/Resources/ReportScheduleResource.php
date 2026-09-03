<?php

namespace App\Filament\Group\Resources;

use App\Filament\Group\Resources\ReportScheduleResource\Pages;
use App\Models\GroupMember;
use App\Models\GroupReportSchedule;
use App\Services\Group\ReportRegistry;
use App\Services\Group\ScheduleDueResolver;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Programmations d'envoi des états du portail.
 *
 * Tout est cloisonné au groupe du membre connecté : la requête, la liste des
 * destinataires proposés, et le groupe écrit à la création. Une programmation
 * porte des données financières consolidées ; elle ne doit jamais franchir la
 * frontière d'un groupe, ni dans un sens ni dans l'autre.
 */
class ReportScheduleResource extends Resource
{
    protected static ?string $model = GroupReportSchedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Rapports programmés';

    protected static ?string $navigationGroup = 'Analytiques';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'rapport programmé';

    protected static ?string $pluralModelLabel = 'rapports programmés';

    /**
     * Tant que le drapeau est éteint, l'écran reste caché : laisser créer des
     * programmations qu'aucune tâche n'enverra ferait croire à un envoi qui
     * n'arriverait jamais.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('group_portal.scheduled_reports_enabled', false);
    }

    public static function canAccess(): bool
    {
        return (bool) config('group_portal.scheduled_reports_enabled', false);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('group_id', auth('group')->user()?->group_id);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Le document')
                ->schema([
                    Forms\Components\Select::make('report_key')
                        ->label('État à envoyer')
                        ->options(fn (ReportRegistry $registry) => $registry->options())
                        ->required()
                        ->native(false),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Programmation active')
                        ->default(true)
                        ->helperText('Désactiver suspend les envois sans perdre le réglage.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Quand')
                ->description("L'envoi part une fois par période, dès que le moment prévu est passé. Un serveur occupé rattrape plus tard dans la journée ; il n'envoie pas deux fois.")
                ->schema([
                    Forms\Components\Select::make('frequency')
                        ->label('Fréquence')
                        ->options([
                            ScheduleDueResolver::HEBDOMADAIRE => 'Chaque semaine',
                            ScheduleDueResolver::MENSUEL => 'Chaque mois',
                        ])
                        ->default(ScheduleDueResolver::HEBDOMADAIRE)
                        ->required()
                        ->live()
                        ->native(false),

                    Forms\Components\Select::make('day_of_week')
                        ->label('Jour de la semaine')
                        ->options([
                            1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
                            5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
                        ])
                        ->default(1)
                        ->native(false)
                        ->visible(fn (Forms\Get $get) => $get('frequency') === ScheduleDueResolver::HEBDOMADAIRE)
                        ->required(fn (Forms\Get $get) => $get('frequency') === ScheduleDueResolver::HEBDOMADAIRE),

                    Forms\Components\Select::make('day_of_month')
                        ->label('Jour du mois')
                        ->options(array_combine(range(1, 31), range(1, 31)))
                        ->default(1)
                        ->native(false)
                        ->helperText('Un jour absent du mois est ramené au dernier jour (le 31 devient le 28 en février).')
                        ->visible(fn (Forms\Get $get) => $get('frequency') === ScheduleDueResolver::MENSUEL)
                        ->required(fn (Forms\Get $get) => $get('frequency') === ScheduleDueResolver::MENSUEL),

                    Forms\Components\Select::make('hour')
                        ->label('Heure')
                        ->options(collect(range(0, 23))->mapWithKeys(fn ($h) => [$h => sprintf('%02dh00', $h)])->all())
                        ->default(7)
                        ->required()
                        ->native(false),
                ])
                ->columns(2),

            Forms\Components\Section::make('Destinataires')
                ->description('Uniquement des membres du groupe. Un état consolidé ne peut pas être programmé vers une adresse extérieure.')
                ->schema([
                    Forms\Components\CheckboxList::make('recipient_member_ids')
                        ->label('Membres')
                        ->options(fn () => static::membresDuGroupe())
                        ->descriptions(fn () => static::adressesDesMembres())
                        ->required()
                        ->columns(2)
                        ->helperText('Un membre désactivé ou sans adresse est ignoré à l\'envoi.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('report_key')
                    ->label('État')
                    ->formatStateUsing(fn (string $state, ReportRegistry $registry) => $registry->libelle($state))
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('frequency')
                    ->label('Rythme')
                    ->formatStateUsing(fn (GroupReportSchedule $record) => static::rythmeLisible($record))
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('recipient_member_ids')
                    ->label('Destinataires')
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state) : 0)
                    ->alignRight(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_sent_at')
                    ->label('Dernier envoi')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('jamais')
                    ->sortable(),

                // Une programmation qui échoue en silence est pire que pas de
                // programmation : le directeur croit recevoir son état.
                Tables\Columns\TextColumn::make('last_error')
                    ->label('Dernier échec')
                    ->limit(40)
                    ->tooltip(fn (?string $state) => $state)
                    ->color('danger')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Aucun envoi programmé')
            ->emptyStateDescription('Programmez un état pour le recevoir par e-mail sans avoir à ouvrir le portail.')
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<int, string> */
    public static function membresDuGroupe(): array
    {
        return GroupMember::query()
            ->where('group_id', auth('group')->user()?->group_id)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> */
    public static function adressesDesMembres(): array
    {
        return GroupMember::query()
            ->where('group_id', auth('group')->user()?->group_id)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->pluck('email', 'id')
            ->all();
    }

    public static function rythmeLisible(GroupReportSchedule $record): string
    {
        $heure = sprintf('%02dh00', (int) $record->hour);

        if ($record->frequency === ScheduleDueResolver::MENSUEL) {
            return "Le {$record->day_of_month} du mois à {$heure}";
        }

        $jours = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
        $jour = $jours[$record->day_of_week] ?? 'lundi';

        return "Chaque {$jour} à {$heure}";
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReportSchedules::route('/'),
            'create' => Pages\CreateReportSchedule::route('/create'),
            'edit' => Pages\EditReportSchedule::route('/{record}/edit'),
        ];
    }
}
