<?php

namespace App\Services\Saas;

use App\Models\Group;
use App\Models\SaasMappingReviewEvent;
use App\Models\SaasMappingReviewRecord;
use App\Models\SaasProduct;
use App\Models\SaasProductInstance;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Intention commerciale et revue Maker/Checker, sans jamais modifier les
 * groupes historiques, contrats, quotas, comptes, installations ou registre.
 */
final class SaasMappingWorkflow
{
    private const KINDS = ['school', 'technical', 'demonstration'];

    /** @param array<string, mixed> $data */
    public function saveDraft(User $actor, array $data, ?int $reviewId = null): SaasMappingReviewRecord
    {
        Gate::forUser($actor)->authorize(SaasCapabilities::PROPOSE);

        $validated = Validator::make($data, [
            'legacy_tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')],
            'kind' => ['required', Rule::in(self::KINDS)],
            'proposed_group_id' => ['nullable', 'integer', Rule::exists('groups', 'id')],
            'product_id' => ['nullable', 'integer', Rule::exists('saas_products', 'id')],
            'evidence_reference' => ['required', 'string', 'max:255', 'min:6'],
        ])->validate();

        return DB::transaction(function () use ($actor, $validated, $reviewId): SaasMappingReviewRecord {
            $tenant = Tenant::withTrashed()->findOrFail($validated['legacy_tenant_id']);
            if ($tenant->trashed()) {
                throw ValidationException::withMessages(['legacy_tenant_id' => 'Instance archivée : rapprochement interdit.']);
            }
            $groupId = $validated['proposed_group_id'] ?? null;
            $productId = $validated['product_id'] ?? null;
            if ($groupId !== null && Group::withTrashed()->findOrFail($groupId)->trashed()) {
                throw ValidationException::withMessages(['proposed_group_id' => 'Groupe archivé : choisissez un groupe actif.']);
            }
            if ($validated['kind'] !== 'school' && ($groupId !== null || $productId !== null)) {
                throw ValidationException::withMessages(['kind' => 'Les instances techniques ne sont pas des établissements facturables.']);
            }

            $record = $reviewId !== null
                ? SaasMappingReviewRecord::query()->lockForUpdate()->findOrFail($reviewId)
                : SaasMappingReviewRecord::query()->where('legacy_tenant_id', $tenant->id)->lockForUpdate()->first();

            if ($record !== null) {
                if ((int) $record->legacy_tenant_id !== (int) $tenant->id
                    || (int) $record->created_by !== (int) $actor->id) {
                    abort(403);
                }
                if (! in_array($record->state, ['draft', 'rejected'], true)) {
                    throw ValidationException::withMessages(['state' => 'Soumission déjà envoyée : aucune modification silencieuse.']);
                }
                $fromState = $record->state;
                $record->revision++;
            } else {
                $record = new SaasMappingReviewRecord();
                $record->legacy_tenant_id = $tenant->id;
                $record->created_by = $actor->id;
                $record->revision = 1;
                $fromState = null;
            }

            $record->kind = $validated['kind'];
            $record->proposed_group_id = $groupId;
            $record->product_id = $productId;
            $record->evidence_reference = $validated['evidence_reference'];
            $record->state = 'draft';
            $record->review_note = null;
            $record->reviewed_by = null;
            $record->reviewed_at = null;
            $record->submitted_at = null;
            $record->save();

            $this->recordEvent($record, $actor, 'draft_saved', $fromState, [
                'kind' => $record->kind,
                'proposed_group_id' => $record->proposed_group_id,
                'product_id' => $record->product_id,
                'evidence_reference' => $record->evidence_reference,
                'revision' => $record->revision,
            ]);

            return $record->refresh();
        });
    }

    public function submit(User $actor, int $reviewId): SaasMappingReviewRecord
    {
        Gate::forUser($actor)->authorize(SaasCapabilities::PROPOSE);

        return DB::transaction(function () use ($actor, $reviewId) {
            $record = SaasMappingReviewRecord::query()->lockForUpdate()->findOrFail($reviewId);
            if ((int) $record->created_by !== (int) $actor->id || $record->state !== 'draft') {
                abort(403);
            }
            $this->validateCurrentReferences($record);

            $record->state = 'submitted';
            $record->submitted_at = now();
            $record->save();
            $this->recordEvent($record, $actor, 'submitted', 'draft', [
                'tenant_id' => $record->legacy_tenant_id,
                'kind' => $record->kind,
                'group_discrepancy' => $this->hasGroupDiscrepancy($record),
                'product_id' => $record->product_id,
            ]);

            return $record->refresh();
        });
    }

    /**
     * "approved" approuve UNIQUEMENT la classification proposee.
     * Rien n'est importe, provisionne, facture ou rattache automatiquement.
     */
    public function decide(
        User $actor,
        int $reviewId,
        bool $approve,
        string $note,
        bool $acknowledgeGroupDiscrepancy = false
    ): SaasMappingReviewRecord {
        Gate::forUser($actor)->authorize(SaasCapabilities::REVIEW);

        return DB::transaction(function () use ($actor, $reviewId, $approve, $note, $acknowledgeGroupDiscrepancy) {
            $record = SaasMappingReviewRecord::query()->lockForUpdate()->findOrFail($reviewId);
            if ($record->state !== 'submitted' || (int) $record->created_by === (int) $actor->id) {
                abort(403);
            }
            if (mb_strlen(trim($note)) < 10 || mb_strlen(trim($note)) > 1000) {
                throw ValidationException::withMessages(['review_note' => 'Justification obligatoire (10 à 1000 caractères).']);
            }

            if ($approve) {
                $this->validateCurrentReferences($record);
                if ($this->hasGroupDiscrepancy($record) && ! $acknowledgeGroupDiscrepancy) {
                    throw ValidationException::withMessages([
                        'group_discrepancy' => "Écart avec le groupe historique : reconnaissance explicite obligatoire. L'accès SSO n'est pas modifié.",
                    ]);
                }
            }

            $record->state = $approve ? 'approved' : 'rejected';
            $record->review_note = trim($note);
            $record->reviewed_by = $actor->id;
            $record->reviewed_at = now();
            $record->save();

            $this->recordEvent($record, $actor, $approve ? 'approved' : 'rejected', 'submitted', [
                'group_discrepancy' => $this->hasGroupDiscrepancy($record),
                'discrepancy_acknowledged' => $acknowledgeGroupDiscrepancy,
                'review_note' => trim($note),
            ]);

            return $record->refresh();
        });
    }

    public function hasGroupDiscrepancy(SaasMappingReviewRecord $record): bool
    {
        $tenant = Tenant::withTrashed()->find($record->legacy_tenant_id);

        return $record->kind === 'school'
            && $tenant !== null
            && (int) ($tenant->group_id ?? 0) !== (int) ($record->proposed_group_id ?? 0);
    }

    private function validateCurrentReferences(SaasMappingReviewRecord $record): void
    {
        $tenant = Tenant::withTrashed()->find($record->legacy_tenant_id);
        if (! $tenant || $tenant->trashed()) {
            throw ValidationException::withMessages(['legacy_tenant_id' => 'Instance archivée ou introuvable.']);
        }
        if (SaasProductInstance::query()->where('legacy_tenant_id', $tenant->id)->exists()) {
            throw ValidationException::withMessages(['legacy_tenant_id' => 'Instance déjà rapprochée : doublon interdit.']);
        }
        if ($record->kind !== 'school') {
            if ($record->product_id !== null || $record->proposed_group_id !== null) {
                throw ValidationException::withMessages(['kind' => 'Une instance technique ne reçoit aucun groupe ni produit.']);
            }

            return;
        }

        $product = SaasProduct::query()->find($record->product_id);
        if (! $product || ! $product->is_active) {
            throw ValidationException::withMessages(['product_id' => 'Produit indisponible : activer le catalogue validé avant soumission.']);
        }
        if ($record->proposed_group_id !== null) {
            $group = Group::withTrashed()->find($record->proposed_group_id);
            if (! $group || $group->trashed()) {
                throw ValidationException::withMessages(['proposed_group_id' => 'Groupe absent ou archivé.']);
            }
        }
    }

    /** @param array<string, mixed> $details */
    private function recordEvent(SaasMappingReviewRecord $record, User $actor, string $action, ?string $from, array $details): void
    {
        $event = new SaasMappingReviewEvent();
        $event->saas_mapping_review_id = $record->id;
        $event->actor_id = $actor->id;
        $event->action = $action;
        $event->from_state = $from;
        $event->to_state = $record->state;
        $event->details = $details;
        $event->save();
    }
}
