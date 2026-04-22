<?php

namespace App\Filament\Group\Pages;

use App\Enums\AlertType;
use App\Filament\Group\Concerns\HasCustomHero;
use App\Models\GroupMemberNotificationPreference;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Self-service preferences for group-portal email notifications. A founder
 * or director who lands in their inbox full of critical alerts doesn't want
 * to hunt for a signed unsubscribe link in last week's email — they want a
 * form. This page is that form.
 */
class NotificationPreferences extends Page implements HasForms
{
    use HasCustomHero;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $navigationGroup = 'Mon compte';

    protected static ?string $title = 'Préférences de notification';

    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.group.pages.notification-preferences';

    protected static ?string $slug = 'mes-preferences-notifications';

    public ?array $data = [];

    public function mount(): void
    {
        $prefs = $this->loadPrefs();

        $this->form->fill([
            'email_enabled' => (bool) $prefs->email_enabled,
            'immediate_critical' => (bool) $prefs->immediate_critical,
            'daily_digest_warnings' => (bool) $prefs->daily_digest_warnings,
            'digest_time' => $prefs->digest_time,
            'dedup_hours' => (int) $prefs->dedup_hours,
            'enabled_alert_types' => $this->enabledTypesFor($prefs),
        ]);
    }

    public function form(Form $form): Form
    {
        $alertTypeToggles = [];
        foreach (AlertType::cases() as $type) {
            $alertTypeToggles[] = Toggle::make("enabled_alert_types.{$type->value}")
                ->label($this->labelFor($type))
                ->helperText($this->helperFor($type))
                ->onColor('primary')
                ->offColor('gray');
        }

        return $form
            ->schema([
                Section::make('Canal principal')
                    ->description('Interrupteur global — quand désactivé, aucun email n\'est envoyé peu importe les préférences individuelles.')
                    ->schema([
                        Toggle::make('email_enabled')
                            ->label('Recevoir des emails de notification')
                            ->onColor('primary')
                            ->helperText('Si désactivé, les options ci-dessous sont ignorées.'),
                    ])
                    ->collapsible(),

                Section::make('Cadence')
                    ->description('Comment vous souhaitez recevoir les alertes.')
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('immediate_critical')
                                ->label('Critiques — immédiat')
                                ->helperText('Emails individuels envoyés dès qu\'une alerte critique est détectée.'),

                            Toggle::make('daily_digest_warnings')
                                ->label('Avertissements — récapitulatif quotidien')
                                ->helperText('Un email par jour regroupant les avertissements non critiques.'),
                        ]),
                        Grid::make(2)->schema([
                            Select::make('digest_time')
                                ->label('Heure du récapitulatif')
                                ->options($this->digestTimeOptions())
                                ->required()
                                ->native(false)
                                ->helperText('Heure d\'envoi du récapitulatif quotidien (fuseau Abidjan).'),

                            Select::make('dedup_hours')
                                ->label('Fenêtre anti-doublons')
                                ->options([
                                    6 => '6 heures',
                                    12 => '12 heures',
                                    24 => '24 heures (recommandé)',
                                    48 => '48 heures',
                                    72 => '72 heures',
                                ])
                                ->required()
                                ->native(false)
                                ->helperText('Délai minimum entre deux notifications pour la même alerte.'),
                        ]),
                    ])
                    ->collapsible(),

                Section::make('Types d\'alertes')
                    ->description('Désactivez les types d\'alertes qui ne vous concernent pas. Chaque toggle est indépendant.')
                    ->schema([
                        Grid::make(2)->schema($alertTypeToggles),
                    ])
                    ->collapsible(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $enabledTypes = (array) ($state['enabled_alert_types'] ?? []);
        $disabled = [];
        foreach (AlertType::cases() as $type) {
            if (empty($enabledTypes[$type->value])) {
                $disabled[] = $type->value;
            }
        }

        $prefs = $this->loadPrefs();
        $prefs->update([
            'email_enabled' => (bool) ($state['email_enabled'] ?? false),
            'immediate_critical' => (bool) ($state['immediate_critical'] ?? false),
            'daily_digest_warnings' => (bool) ($state['daily_digest_warnings'] ?? false),
            'digest_time' => (string) ($state['digest_time'] ?? '08:00'),
            'dedup_hours' => (int) ($state['dedup_hours'] ?? 24),
            'disabled_alert_types' => $disabled,
        ]);

        Notification::make()
            ->title('Préférences enregistrées')
            ->body('Vos préférences de notification ont été mises à jour.')
            ->success()
            ->send();
    }

    private function loadPrefs(): GroupMemberNotificationPreference
    {
        return GroupMemberNotificationPreference::forMember(auth('group')->user());
    }

    /**
     * Inverts the `disabled_alert_types` list so the form shows each type
     * toggled ON by default (users opt OUT, not IN — default verbose wins
     * over silent). Returns a map of `['type_value' => bool]`.
     */
    private function enabledTypesFor(GroupMemberNotificationPreference $prefs): array
    {
        $disabled = (array) ($prefs->disabled_alert_types ?? []);
        $enabled = [];

        foreach (AlertType::cases() as $type) {
            $enabled[$type->value] = ! in_array($type->value, $disabled, true);
        }

        return $enabled;
    }

    /**
     * Hourly slots + a few practical mid-hour offsets. We don't need a free
     * text input — digest arrivals are non-critical so 30-min granularity
     * is plenty and avoids per-member timezone debugging.
     */
    private function digestTimeOptions(): array
    {
        $options = [];
        foreach (range(6, 20) as $hour) {
            foreach (['00', '30'] as $minute) {
                $slot = sprintf('%02d:%s', $hour, $minute);
                $options[$slot] = $slot;
            }
        }

        return $options;
    }

    private function labelFor(AlertType $type): string
    {
        return match ($type) {
            AlertType::QuotaExceeded => 'Quota dépassé',
            AlertType::QuotaCritical => 'Quota critique',
            AlertType::SubscriptionExpired => 'Abonnement expiré',
            AlertType::SubscriptionExpiring => 'Abonnement expirant',
            AlertType::HighAttrition => 'Attrition élevée',
            AlertType::ActiveReliquats => 'Reliquats actifs',
            AlertType::PlanMismatch => 'Plan dépassé',
            AlertType::StaleTenant => 'Tenant inactif',
            AlertType::SslExpiring => 'Certificat SSL expirant',
            AlertType::EnrollmentDecline => 'Inscriptions en baisse',
            AlertType::UnpaidInvoices => 'Factures impayées',
            AlertType::TeacherOverload => 'Surcharge enseignante',
        };
    }

    private function helperFor(AlertType $type): string
    {
        return match ($type) {
            AlertType::QuotaExceeded, AlertType::QuotaCritical => 'Quotas d\'utilisateurs ou de stockage d\'un établissement.',
            AlertType::SubscriptionExpired, AlertType::SubscriptionExpiring => 'Date d\'expiration de l\'abonnement KLASSCI.',
            AlertType::HighAttrition => 'Taux d\'abandon d\'inscriptions élevé d\'une année à l\'autre.',
            AlertType::ActiveReliquats => 'Restes à payer reportés d\'années antérieures.',
            AlertType::PlanMismatch => 'Un établissement dépasse le nombre d\'étudiants prévu par son plan.',
            AlertType::StaleTenant => 'Tenant non déployé depuis longtemps ou en échec de health check.',
            AlertType::SslExpiring => 'Certificat SSL d\'un établissement proche de l\'expiration.',
            AlertType::EnrollmentDecline => 'Deux mois consécutifs de baisse d\'inscriptions.',
            AlertType::UnpaidInvoices => 'Factures émises non payées.',
            AlertType::TeacherOverload => 'Enseignants dépassant les heures hebdomadaires maximales.',
        };
    }
}
