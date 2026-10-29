<?php

namespace App\Services\Saas;

use App\Models\Group;
use App\Models\SaasEstablishment;
use App\Models\SaasOrganization;
use App\Models\SaasProduct;
use App\Models\SaasProductInstance;
use App\Models\Tenant;

/**
 * Inventaire strictement en lecture seule : aucune inference d'ecole
 * par nom, URL, branche Git ou moteur d'execution.
 *
 * Ne lit jamais database_credentials, api_token, coordonnees personnelles
 * ou metadata ; les compteurs et statuts sont seulement ceux du master.
 */
final class LegacyInventoryReport
{
    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        // Inclure les groupes/tenants soft-deleted pour identifier les
        // rattachements obsoletes. Aucune connexion a leurs bases metier.
        $groups = Group::withTrashed()
            ->get(['id', 'code', 'name', 'deleted_at'])
            ->keyBy('id');
        $tenants = Tenant::withTrashed()
            ->get(['id', 'code', 'name', 'group_id', 'status', 'plan', 'deleted_at'])
            ->keyBy('id');
        $organizations = SaasOrganization::query()
            ->get(['id', 'name', 'legacy_group_id'])
            ->keyBy('id');
        $establishments = SaasEstablishment::query()
            ->get(['id', 'organization_id', 'product_id', 'name', 'slug'])
            ->keyBy('id');
        $products = SaasProduct::query()
            ->get(['id', 'code', 'establishment_type', 'adapter_key', 'is_active'])
            ->keyBy('id');
        $instances = SaasProductInstance::query()
            ->get(['id', 'establishment_id', 'product_id', 'legacy_tenant_id', 'external_tenant_code', 'status'])
            ->keyBy('id');

        $orgByGroup = $organizations
            ->filter(fn (SaasOrganization $org) => $org->legacy_group_id !== null)
            ->keyBy('legacy_group_id');
        $instanceByTenant = $instances
            ->filter(fn (SaasProductInstance $instance) => $instance->legacy_tenant_id !== null)
            ->keyBy('legacy_tenant_id');

        $groupRows = [];
        foreach ($groups as $group) {
            $org = $orgByGroup->get($group->id);
            $groupRows[] = [
                'group_id' => $group->id,
                'group_code' => $group->code,
                'group_name' => $group->name,
                'archived' => $group->trashed(),
                'legacy_tenant_count' => $tenants->where('group_id', $group->id)->count(),
                'saas_organization_id' => $org?->id,
                'link_status' => $org === null ? 'a_valider' : 'lien_explicite',
            ];
        }

        $tenantRows = [];
        foreach ($tenants as $tenant) {
            $group = $tenant->group_id !== null ? $groups->get($tenant->group_id) : null;
            $groupOrg = $group !== null ? $orgByGroup->get($group->id) : null;
            $instance = $instanceByTenant->get($tenant->id);
            $establishment = $instance !== null
                ? $establishments->get($instance->establishment_id)
                : null;
            $product = $establishment !== null
                ? $products->get($establishment->product_id)
                : null;

            $anomalies = [];
            if ($tenant->group_id !== null && $group === null) {
                $anomalies[] = 'groupe_historique_introuvable';
            } elseif ($group !== null && $group->trashed()) {
                $anomalies[] = 'groupe_historique_archive';
            }

            if ($instance === null) {
                $anomalies[] = 'instance_saas_non_rattachee';
                $anomalies[] = 'categorie_a_confirmer';
            } else {
                if ($establishment === null || $product === null) {
                    $anomalies[] = 'reference_saas_incomplete';
                }
                if ($group !== null && $groupOrg === null) {
                    $anomalies[] = 'groupe_saas_non_rattache';
                }
                if ($group !== null && $groupOrg !== null
                    && $groupOrg->id !== $establishment?->organization_id) {
                    $anomalies[] = 'conflit_appartenance_groupe';
                }
                if ($group === null && $establishment !== null) {
                    $org = $organizations->get($establishment->organization_id);
                    if ($org?->legacy_group_id !== null) {
                        $anomalies[] = 'groupe_legacy_manquant_sur_tenant';
                    }
                }
            }
            if ($tenant->trashed()) {
                $anomalies[] = 'tenant_historique_archive';
            }

            $tenantRows[] = [
                'legacy_tenant_id' => $tenant->id,
                'legacy_code' => $tenant->code,
                'legacy_name' => $tenant->name,
                'legacy_group_id' => $tenant->group_id,
                'legacy_group_code' => $group?->code,
                'legacy_status' => $tenant->status,
                'legacy_plan' => $tenant->plan,
                'archived' => $tenant->trashed(),
                'saas_instance_id' => $instance?->id,
                'saas_establishment_id' => $establishment?->id,
                'saas_organization_id' => $establishment?->organization_id,
                'product_code' => $product?->code,
                'establishment_type' => $product?->establishment_type,
                'review_reasons' => $anomalies,
            ];
        }

        $saasRows = [];
        foreach ($establishments as $establishment) {
            $org = $organizations->get($establishment->organization_id);
            $product = $products->get($establishment->product_id);
            $instance = $instances->firstWhere('establishment_id', $establishment->id);
            $saasRows[] = [
                'saas_establishment_id' => $establishment->id,
                'saas_organization_id' => $establishment->organization_id,
                'legacy_group_id' => $org?->legacy_group_id,
                'name' => $establishment->name,
                'slug' => $establishment->slug,
                'product_code' => $product?->code,
                'establishment_type' => $product?->establishment_type,
                'adapter_key' => $product?->adapter_key,
                'saas_instance_id' => $instance?->id,
                'legacy_tenant_id' => $instance?->legacy_tenant_id,
                'instance_status' => $instance?->status,
                'has_instance' => $instance !== null,
            ];
        }

        $pending = count(array_filter(
            $tenantRows,
            fn (array $row) => $row['review_reasons'] !== []
        ));

        return [
            'schema_version' => 1,
            'mode' => 'read_only',
            'source' => 'master_database_only',
            'summary' => [
                'legacy_groups' => $groups->count(),
                'groups_linked' => $orgByGroup->count(),
                'legacy_tenants' => $tenants->count(),
                'tenants_explicitly_mapped' => $instanceByTenant->count(),
                'tenants_requiring_review' => $pending,
                'saas_organizations' => $organizations->count(),
                'saas_establishments' => $establishments->count(),
            ],
            'groups' => $groupRows,
            'legacy_tenants' => $tenantRows,
            'saas_establishments' => $saasRows,
            'saas_products' => $products->map(fn (SaasProduct $product) => [
                'code' => $product->code,
                'establishment_type' => $product->establishment_type,
                'adapter_key' => $product->adapter_key,
                'is_active' => (bool) $product->is_active,
            ])->values()->all(),
            'rules' => [
                'never_infer_product_from_name_or_branch',
                'one_product_per_establishment',
                'organization_may_have_multiple_establishments',
                'no_database_or_infrastructure_mutation',
            ],
        ];
    }
}
