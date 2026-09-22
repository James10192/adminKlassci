<?php

namespace App\Filament\Resources\TenantResource\Concerns;

/**
 * Reinjecte dans le formulaire les secrets que Tenant::$hidden masque.
 *
 * Filament remplit ses formulaires via attributesToArray(), qui respecte
 * $hidden : sans ce complement, le champ credentials (requis) s'afficherait
 * vide et le token API disparaitrait de la page de detail.
 *
 * refreshFormData() contourne ce hook : pour rafraichir ces champs, appeler
 * fillForm().
 */
trait ReinjecteLesSecretsDuTenant
{
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $tenant = $this->getRecord();

        return [
            ...$data,
            'database_credentials' => $tenant->database_credentials,
            'api_token' => $tenant->api_token,
        ];
    }
}
