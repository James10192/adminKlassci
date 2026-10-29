<?php

namespace App\Services\Group;

use App\Models\Group;
use App\Models\SaasMappingReviewRecord;
use App\Models\SaasProductInstance;
use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\User;
use App\Services\Saas\SaasCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Unique porte d'ecriture admin pour le rattachement des tenants historiques
 * au portail Groupe. Ne cree ni organisation SaaS, ni contrat, ni role SSO.
 */
final class GroupTenantMembershipService
{
    public function associate(User $actor, int $groupId, int $tenantId, string $reason): Tenant
    {
        Gate::forUser($actor)->authorize(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP);
        $this->assertReason($reason);

        return DB::transaction(function () use ($actor, $groupId, $tenantId, $reason): Tenant {
            $group = $this->activeGroup($groupId);
            $tenant = Tenant::withTrashed()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $this->assertTenantIsEditable($tenant);

            if ($tenant->group_id !== null) {
                throw ValidationException::withMessages([
                    'tenant_id' => (int) $tenant->group_id === $group->id
                        ? 'Cet établissement fait déjà partie du groupe.'
                        : 'Cet établissement appartient déjà à un autre groupe. Un transfert distinct et approuvé est nécessaire.',
                ]);
            }

            $tenant->group_id = $group->id;
            $tenant->save();

            $this->audit($tenant, $actor, 'group_associated', null, $group->id, $reason);

            return $tenant->refresh();
        });
    }

    public function dissociate(User $actor, int $groupId, int $tenantId, string $reason): Tenant
    {
        Gate::forUser($actor)->authorize(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP);
        $this->assertReason($reason);

        return DB::transaction(function () use ($actor, $groupId, $tenantId, $reason): Tenant {
            $group = $this->activeGroup($groupId);
            $tenant = Tenant::withTrashed()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $this->assertTenantIsEditable($tenant);

            if ((int) $tenant->group_id !== (int) $group->id) {
                throw ValidationException::withMessages([
                    'tenant_id' => 'Cet établissement ne fait plus partie de ce groupe. Actualisez la liste.',
                ]);
            }

            $tenant->group_id = null;
            $tenant->save();

            $this->audit($tenant, $actor, 'group_dissociated', $group->id, null, $reason);

            return $tenant->refresh();
        });
    }

    private function activeGroup(int $groupId): Group
    {
        $group = Group::withTrashed()->whereKey($groupId)->lockForUpdate()->firstOrFail();
        if ($group->trashed() || $group->status !== 'active') {
            throw ValidationException::withMessages([
                'group' => 'Le groupe est archivé ou suspendu. Cette opération est indisponible.',
            ]);
        }

        return $group;
    }

    private function assertTenantIsEditable(Tenant $tenant): void
    {
        if ($tenant->trashed()) {
            throw ValidationException::withMessages(['tenant_id' => 'Instance archivée : impossible de modifier son rattachement.']);
        }

        // Les nouveaux dossiers commerciaux soumis ou approuves doivent rester
        // coherents avec le snapshot SSO. Ne jamais les contourner ici.
        if (Schema::hasTable('saas_mapping_reviews')
            && SaasMappingReviewRecord::query()
                ->where('legacy_tenant_id', $tenant->id)
                ->whereIn('state', ['submitted', 'approved'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'tenant_id' => 'Un rapprochement SaaS est soumis ou approuvé : faites réexaminer ce dossier avant de changer le groupe historique.',
            ]);
        }

        if (Schema::hasTable('saas_product_instances')
            && SaasProductInstance::query()->where('legacy_tenant_id', $tenant->id)->exists()
        ) {
            throw ValidationException::withMessages([
                'tenant_id' => 'Cette instance est déjà reliée au registre SaaS. Un workflow de transfert contrôlé est nécessaire.',
            ]);
        }
    }

    private function assertReason(string $reason): void
    {
        $length = mb_strlen(trim($reason));
        if ($length < 10 || $length > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Précisez une justification de 10 à 500 caractères pour tracer cette modification.',
            ]);
        }
    }

    private function audit(
        Tenant $tenant,
        User $actor,
        string $action,
        ?int $fromGroup,
        ?int $toGroup,
        string $reason
    ): void {
        // Le journal existant est ecrit dans la MEME transaction.
        // Toute erreur d'audit annule egalement le changement de groupe.
        TenantActivityLog::log(
            $tenant->id,
            $action,
            'Modification contrôlée du rattachement au portail Groupe',
            $actor->id,
            [
                'previous_group_id' => $fromGroup,
                'new_group_id' => $toGroup,
                'reason' => trim($reason),
                'source' => 'admin.group.tenants',
            ]
        );
    }
}
