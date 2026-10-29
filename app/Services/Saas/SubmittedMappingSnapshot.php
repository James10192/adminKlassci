<?php

namespace App\Services\Saas;

use App\Models\SaasMappingReviewRecord;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * Contrôle l'identité de la proposition entre soumission et décision.
 * L'ancien groupe peut changer ailleurs (portail historique/SSO).
 * Une validation doit alors être recommencée ; aucune adoption implicite.
 */
final class SubmittedMappingSnapshot
{
    /** @return array{historical_group_id: int|null, proposed_group_id: int|null, product_id: int|null, kind: string, revision: int} */
    public function capture(SaasMappingReviewRecord $record): array
    {
        $tenant = Tenant::withTrashed()
            ->select(['id', 'group_id', 'deleted_at'])
            ->whereKey($record->legacy_tenant_id)
            ->lockForUpdate()
            ->firstOrFail();

        return [
            'historical_group_id' => $tenant->group_id === null ? null : (int) $tenant->group_id,
            'proposed_group_id' => $record->proposed_group_id === null ? null : (int) $record->proposed_group_id,
            'product_id' => $record->product_id === null ? null : (int) $record->product_id,
            'kind' => $record->kind,
            'revision' => $record->revision,
        ];
    }

    public function assertUnchanged(SaasMappingReviewRecord $record): void
    {
        $submittedEvent = $record->events()
            ->where('action', 'submitted')
            ->orderByDesc('id')
            ->first();

        $reference = $submittedEvent?->details;

        // Pas de rétrocompatibilité permissive : les anciens dossiers sans
        // snapshot doivent être refusés/re-soumis par leur auteur.
        if (! is_array($reference)
            || ! array_key_exists('historical_group_id', $reference)
            || ! array_key_exists('proposed_group_id', $reference)
            || ! array_key_exists('product_id', $reference)
            || ! array_key_exists('kind', $reference)
            || ! array_key_exists('revision', $reference)
        ) {
            throw ValidationException::withMessages([
                'review_note' => 'Contrôle historique indisponible : renvoyez ce dossier à son auteur pour nouvelle soumission.',
            ]);
        }

        $current = $this->capture($record);

        foreach (array_keys($current) as $field) {
            if ($reference[$field] !== $current[$field]) {
                throw ValidationException::withMessages([
                    'review_note' => 'Les informations du groupe ou de la proposition ont changé depuis la soumission. Renvoyez le dossier pour réexamen.',
                ]);
            }
        }
    }
}
