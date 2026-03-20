<?php

namespace App\Filament\Pages\TenantConfig;

use App\Filament\Traits\TenantConfigTrait;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SettingsPage extends Page
{
    use TenantConfigTrait;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Settings PDF';
    protected static ?string $navigationGroup = 'Configuration Tenants';
    protected static ?int $navigationSort = 4;
    protected static string $view = 'filament.pages.tenant-config.settings';
    protected static ?string $title = 'Paramètres PDF & Bulletin';

    public array $tenants = [];
    public array $settings = [];
    public array $formValues = [];

    protected array $settingGroups = [
        'pdf_colors' => [
            'label' => 'Couleurs PDF',
            'icon' => 'heroicon-o-swatch',
            'keys' => [
                'pdf_primary_color' => ['label' => 'Couleur primaire', 'type' => 'color', 'default' => '#0453cb'],
                'pdf_secondary_color' => ['label' => 'Couleur secondaire', 'type' => 'color', 'default' => '#64748b'],
                'pdf_accent_color' => ['label' => 'Couleur accent', 'type' => 'color', 'default' => '#f59e0b'],
                'pdf_text_color' => ['label' => 'Couleur texte', 'type' => 'color', 'default' => '#1f2937'],
                'pdf_header_bg_color' => ['label' => 'Fond en-tête', 'type' => 'color', 'default' => '#0453cb'],
                'pdf_header_text_color' => ['label' => 'Texte en-tête', 'type' => 'color', 'default' => '#ffffff'],
            ],
        ],
        'bulletin_display' => [
            'label' => 'Affichage Bulletin',
            'icon' => 'heroicon-o-eye',
            'keys' => [
                'bulletin_show_logo' => ['label' => 'Logo', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_header' => ['label' => 'En-tête', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_republic_info' => ['label' => 'Info République', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_ministry_info' => ['label' => 'Info Ministère', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_school_info' => ['label' => 'Info École', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_student_info' => ['label' => 'Info Étudiant', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_matricule' => ['label' => 'Matricule', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_birth_date' => ['label' => 'Date de naissance', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_teachers' => ['label' => 'Noms enseignants', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_absences' => ['label' => 'Absences', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_statistics' => ['label' => 'Statistiques classe', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_signature' => ['label' => 'Signature', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_council_decision' => ['label' => 'Décision du conseil', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_highest_average' => ['label' => 'Meilleure moyenne', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_lowest_average' => ['label' => 'Plus basse moyenne', 'type' => 'boolean', 'default' => '1'],
                'bulletin_show_class_average' => ['label' => 'Moyenne classe', 'type' => 'boolean', 'default' => '1'],
            ],
        ],
        'bulletin_weights' => [
            'label' => 'Poids Semestres',
            'icon' => 'heroicon-o-scale',
            'keys' => [
                'bulletin_semester1_weight' => ['label' => 'Poids Semestre 1', 'type' => 'number', 'default' => '1'],
                'bulletin_semester2_weight' => ['label' => 'Poids Semestre 2', 'type' => 'number', 'default' => '1'],
            ],
        ],
        'certificat' => [
            'label' => 'Certificat de scolarité',
            'icon' => 'heroicon-o-academic-cap',
            'keys' => [
                'certificat_show_classe' => ['label' => 'Afficher classe', 'type' => 'boolean', 'default' => '1'],
                'certificat_show_niveau' => ['label' => 'Afficher niveau', 'type' => 'boolean', 'default' => '1'],
                'certificat_show_filiere' => ['label' => 'Afficher filière', 'type' => 'boolean', 'default' => '1'],
            ],
        ],
    ];

    public function mount(): void
    {
        $this->tenants = $this->getActiveTenants();
    }

    public function updatedSelectedTenantId(): void
    {
        $this->formValues = [];
        $this->loadSettings();
    }

    public function getSettingGroups(): array
    {
        return $this->settingGroups;
    }

    public function loadSettings(): void
    {
        $db = $this->tenantDb();
        if (! $db) {
            return;
        }

        try {
            $allKeys = [];
            foreach ($this->settingGroups as $group) {
                $allKeys = array_merge($allKeys, array_keys($group['keys']));
            }

            $dbSettings = $db->table('settings')
                ->whereIn('key', $allKeys)
                ->pluck('value', 'key')
                ->toArray();

            // Merge DB values with defaults
            $this->formValues = [];
            foreach ($this->settingGroups as $group) {
                foreach ($group['keys'] as $key => $meta) {
                    $this->formValues[$key] = $dbSettings[$key] ?? $meta['default'];
                }
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erreur')
                ->body('Impossible de charger les settings: ' . $e->getMessage())
                ->danger()
                ->send();
        }

        $this->closeTenantConnection();
    }

    public function saveSettings(): void
    {
        if (! $this->selectedTenantId) {
            Notification::make()->title('Sélectionnez un tenant')->warning()->send();
            return;
        }

        $db = $this->tenantDb();
        if (! $db) {
            return;
        }

        $tenant = $this->getSelectedTenant();
        $updated = 0;

        foreach ($this->settingGroups as $groupKey => $group) {
            foreach ($group['keys'] as $key => $meta) {
                $value = $this->formValues[$key] ?? $meta['default'];

                $db->table('settings')->updateOrInsert(
                    ['key' => $key],
                    [
                        'value' => $value,
                        'type' => $meta['type'] === 'color' ? 'string' : $meta['type'],
                        'group' => $groupKey,
                        'category' => $groupKey,
                        'updated_at' => now(),
                    ]
                );
                $updated++;
            }
        }

        $this->closeTenantConnection();

        $this->logConfigChange(
            'settings_bulk_update',
            "{$updated} settings mis à jour pour {$tenant->name}",
            ['count' => $updated, 'groups' => array_keys($this->settingGroups)]
        );

        Notification::make()
            ->title('Paramètres sauvegardés')
            ->body("{$updated} paramètres mis à jour pour {$tenant->name}.")
            ->success()
            ->send();
    }
}
