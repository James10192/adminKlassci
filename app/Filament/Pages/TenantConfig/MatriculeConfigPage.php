<?php

namespace App\Filament\Pages\TenantConfig;

use App\Filament\Traits\TenantConfigTrait;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class MatriculeConfigPage extends Page
{
    use TenantConfigTrait;

    protected static ?string $navigationIcon = 'heroicon-o-identification';
    protected static ?string $navigationLabel = 'Config Matricule';
    protected static ?string $navigationGroup = 'Configuration Tenants';
    protected static ?int $navigationSort = 2;
    protected static string $view = 'filament.pages.tenant-config.matricule-config';
    protected static ?string $title = 'Configuration des Matricules';

    public array $tenants = [];
    public array $configs = [];

    // Form fields
    public string $formNiveauCode = '';
    public string $formNiveauName = '';
    public string $formPrefixe = '';
    public int $formAnneeFormat = 2;
    public int $formNumeroDigits = 4;
    public string $formDescription = '';
    public ?int $editingId = null;
    public bool $showForm = false;

    public function mount(): void
    {
        $this->tenants = $this->getActiveTenants();
    }

    public function updatedSelectedTenantId(): void
    {
        $this->configs = [];
        $this->resetForm();
        $this->loadConfigs();
    }

    public function loadConfigs(): void
    {
        $db = $this->tenantDb();
        if (! $db) {
            return;
        }

        try {
            $this->configs = $db->table('esbtp_matricule_configs')
                ->orderBy('niveau_etude_code')
                ->get()
                ->map(fn ($c) => (array) $c)
                ->toArray();
        } catch (\Exception $e) {
            $this->configs = [];
            Notification::make()
                ->title('Table non trouvée')
                ->body('La table esbtp_matricule_configs n\'existe pas pour ce tenant.')
                ->warning()
                ->send();
        }

        $this->closeTenantConnection();
    }

    public function openForm(?int $id = null): void
    {
        $this->resetForm();

        if ($id) {
            $config = collect($this->configs)->firstWhere('id', $id);
            if ($config) {
                $this->editingId = $id;
                $this->formNiveauCode = $config['niveau_etude_code'] ?? '';
                $this->formNiveauName = $config['niveau_etude_name'] ?? '';
                $this->formPrefixe = $config['prefixe'] ?? '';
                $this->formAnneeFormat = (int) ($config['annee_format'] ?? 2);
                $this->formNumeroDigits = (int) ($config['numero_digits'] ?? 4);
                $this->formDescription = $config['description'] ?? '';
            }
        }

        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->formNiveauCode = '';
        $this->formNiveauName = '';
        $this->formPrefixe = '';
        $this->formAnneeFormat = 2;
        $this->formNumeroDigits = 4;
        $this->formDescription = '';
        $this->showForm = false;
    }

    public function saveConfig(): void
    {
        $this->validate([
            'formNiveauCode' => 'required|max:50',
            'formNiveauName' => 'required|max:100',
            'formPrefixe' => 'nullable|max:10',
            'formAnneeFormat' => 'required|in:2,4',
            'formNumeroDigits' => 'required|integer|min:3|max:6',
        ]);

        $db = $this->tenantDb();
        if (! $db) {
            return;
        }

        $tenant = $this->getSelectedTenant();
        $data = [
            'niveau_etude_code' => strtoupper($this->formNiveauCode),
            'niveau_etude_name' => $this->formNiveauName,
            'prefixe' => $this->formPrefixe ?: null,
            'annee_format' => $this->formAnneeFormat,
            'numero_digits' => $this->formNumeroDigits,
            'description' => $this->formDescription ?: null,
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($this->editingId) {
            $db->table('esbtp_matricule_configs')
                ->where('id', $this->editingId)
                ->update($data);
            $action = 'updated';
        } else {
            $data['created_at'] = now();
            $db->table('esbtp_matricule_configs')->insert($data);
            $action = 'created';
        }

        $this->closeTenantConnection();

        $this->logConfigChange(
            'matricule_config_' . $action,
            "Config matricule {$action} pour niveau {$data['niveau_etude_code']} sur {$tenant->name}",
            $data
        );

        Notification::make()
            ->title('Configuration sauvegardée')
            ->body("Matricule {$data['niveau_etude_code']} {$action} pour {$tenant->name}.")
            ->success()
            ->send();

        $this->resetForm();
        $this->loadConfigs();
    }

    public function deleteConfig(int $id): void
    {
        $db = $this->tenantDb();
        if (! $db) {
            return;
        }

        $tenant = $this->getSelectedTenant();
        $config = $db->table('esbtp_matricule_configs')->where('id', $id)->first();

        $db->table('esbtp_matricule_configs')->where('id', $id)->delete();
        $this->closeTenantConnection();

        $this->logConfigChange(
            'matricule_config_deleted',
            "Config matricule supprimée (niveau {$config->niveau_etude_code}) sur {$tenant->name}",
            ['deleted_id' => $id, 'niveau' => $config->niveau_etude_code ?? 'unknown']
        );

        Notification::make()
            ->title('Configuration supprimée')
            ->success()
            ->send();

        $this->loadConfigs();
    }

    public function generatePreview(): string
    {
        $year = $this->formAnneeFormat === 4 ? date('Y') : substr(date('Y'), -2);
        $prefix = $this->formPrefixe ?: '';
        $code = strtoupper(substr($this->formNiveauCode, 0, 4));
        $number = str_pad('1', $this->formNumeroDigits, '0', STR_PAD_LEFT);

        return "M{$prefix}{$code}{$year}-{$number}";
    }
}
