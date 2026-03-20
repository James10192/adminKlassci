<?php

namespace App\Filament\Pages\TenantConfig;

use App\Filament\Traits\TenantConfigTrait;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class BulletinStylePage extends Page
{
    use TenantConfigTrait;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Style Bulletin';
    protected static ?string $navigationGroup = 'Configuration Tenants';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.tenant-config.bulletin-style';
    protected static ?string $title = 'Style du Bulletin PDF';

    public string $bulletinStyle = '';

    public function updatedSelectedTenantId(): void
    {
        $this->resetTenantState();
        $this->bulletinStyle = '';
        $this->loadConfig();
    }

    public function loadConfig(): void
    {
        $db = $this->tenantDb();
        if (! $db) {
            return;
        }

        try {
            $setting = $db->table('settings')
                ->where('key', 'bulletin_style')
                ->first();

            $this->bulletinStyle = $setting->value ?? 'yakro';
        } finally {
            $this->closeTenantConnection();
        }
    }

    public function saveConfig(): void
    {
        if (! $this->requireTenant()) return;

        if (! in_array($this->bulletinStyle, ['yakro', 'abidjan'])) {
            Notification::make()->title('Style invalide')->danger()->send();
            return;
        }

        $db = $this->tenantDb();
        if (! $db) {
            return;
        }

        $tenant = $this->getSelectedTenant();

        try {
            $db->table('settings')
                ->updateOrInsert(
                    ['key' => 'bulletin_style'],
                    [
                        'value' => $this->bulletinStyle,
                        'type' => 'string',
                        'group' => 'bulletin',
                        'category' => 'bulletin',
                        'updated_at' => now(),
                    ]
                );
        } finally {
            $this->closeTenantConnection();
        }

        $this->logConfigChange(
            'config_update',
            "Style bulletin changé en '{$this->bulletinStyle}' pour {$tenant->name}",
            ['setting' => 'bulletin_style', 'value' => $this->bulletinStyle]
        );

        Notification::make()
            ->title('Style bulletin mis à jour')
            ->body("Le style '{$this->bulletinStyle}' a été appliqué à {$tenant->name}.")
            ->success()
            ->send();
    }
}
