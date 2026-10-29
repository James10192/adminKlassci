<?php

namespace App\Services\Saas;

/**
 * Relecture d'un manifeste propose par l'operateur, sans aucune mutation.
 * La fonction ne consulte PAS de base : elle compare uniquement l'inventaire
 * deja constitue aux valeurs explicites du fichier prive.
 */
final class SaasMappingReview
{
    /** @param array<string, mixed> $manifest
     *  @param array<string, mixed> $inventory
     *  @return array<string, mixed>
     */
    public function review(array $manifest, array $inventory): array
    {
        if (($manifest['schema_version'] ?? null) !== 1
            || ! is_array($manifest['proposals'] ?? null)
            || ! array_is_list($manifest['proposals'])
            || count($manifest['proposals']) > 300
        ) {
            return ['ok' => false, 'error_code' => 'invalid_manifest_schema'];
        }

        $groups = $inventory['groups'] ?? [];
        $tenants = $inventory['legacy_tenants'] ?? [];
        $products = $inventory['saas_products'] ?? [];

        // Les produits sont lus directement de l'inventaire s'ils y sont
        // projetes. Ne JAMAIS deviner un type a partir de l'adapter.
        $groupCodes = [];
        foreach ($groups as $group) {
            $key = strtolower((string) $group['group_code']);
            $groupCodes[$key][] = $group;
        }

        $tenantCodes = [];
        foreach ($tenants as $tenant) {
            $key = strtolower((string) $tenant['legacy_code']);
            $tenantCodes[$key][] = $tenant;
        }

        $results = [];
        $alreadySeen = [];
        foreach ($manifest['proposals'] as $index => $proposal) {
            $issues = [];
            if (! is_array($proposal)) {
                $results[] = ['position' => $index + 1, 'status' => 'blocked', 'issues' => ['invalid_proposal']];

                continue;
            }

            // JSON est une entree non fiable : aucune conversion de tableaux
            // vers string (warnings PHP convertibles en exceptions).
            $codeValue = $proposal['tenant_code'] ?? null;
            $typeValue = $proposal['establishment_type'] ?? null;
            $groupValue = $proposal['group_code'] ?? null;
            $evidenceValue = $proposal['evidence_reference'] ?? null;

            $code = is_string($codeValue) ? trim($codeValue) : '';
            $kind = $proposal['kind'] ?? '';
            $type = is_string($typeValue) ? trim($typeValue) : '';
            $groupCode = is_string($groupValue) ? trim($groupValue) : '';
            $evidence = is_string($evidenceValue) ? trim($evidenceValue) : '';
            $key = strtolower($code);

            if ($groupValue !== null && ! is_string($groupValue)) {
                $issues[] = 'invalid_group_code';
            }

            if ($code === '' || strlen($code) > 100 || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $code)) {
                $issues[] = 'invalid_tenant_code';
            }
            if (isset($alreadySeen[$key])) {
                $issues[] = 'duplicate_proposal';
            }
            $alreadySeen[$key] = true;
            if ($evidence === '' || strlen($evidence) > 255) {
                $issues[] = 'evidence_reference_required';
            }

            if (! in_array($kind, ['school', 'technical', 'demonstration'], true)) {
                $issues[] = 'invalid_kind';
            }

            $matches = $tenantCodes[$key] ?? [];
            if (count($matches) === 0) {
                $issues[] = 'unknown_legacy_tenant';
            } elseif (count($matches) > 1) {
                $issues[] = 'ambiguous_legacy_tenant';
            }

            $tenant = count($matches) === 1 ? $matches[0] : null;
            if (($tenant['archived'] ?? false) === true) {
                $issues[] = 'archived_legacy_tenant';
            }

            if ($kind === 'school') {
                if ($type === '' || strlen($type) > 64 || ! preg_match('/^[a-z][a-z0-9_]*$/', $type)) {
                    $issues[] = 'explicit_establishment_type_required';
                }

                // Le premier lot de registre n'a pas encore de catalogue
                // renseigne ; ne pas valider une categorie invérifiable.
                $matchingTypes = array_filter(
                    $products,
                    fn (array $product) => ($product['establishment_type'] ?? null) === $type
                );
                if ($type !== '' && count($matchingTypes) !== 1) {
                    $issues[] = 'product_catalogue_not_ready';
                }

                if ($groupCode !== '') {
                    $groupMatches = $groupCodes[strtolower($groupCode)] ?? [];
                    if (count($groupMatches) !== 1) {
                        $issues[] = count($groupMatches) === 0
                            ? 'unknown_legacy_group' : 'ambiguous_legacy_group';
                    } elseif (($groupMatches[0]['archived'] ?? false) === true) {
                        $issues[] = 'archived_legacy_group';
                    }
                }

                $historicalGroup = (string) ($tenant['legacy_group_code'] ?? '');
                if ($tenant !== null && strcasecmp($historicalGroup, $groupCode) !== 0) {
                    // L'exemple USAT -> ROSTAN se retrouve ici : le fait
                    // commercial ne donne pas le droit de muter group_id.
                    $issues[] = 'historical_group_disagreement';
                }
                if ($tenant !== null && $tenant['saas_instance_id'] !== null) {
                    $issues[] = 'already_mapped_review_existing';
                }
            } else {
                // Technical/demo est une exclusion seulement DECLAREE par
                // l'operateur, pas determinee par le nom presentation/lms.
                if ($groupCode !== '' || $type !== '') {
                    $issues[] = 'non_school_must_not_assign_group_or_product';
                }
                if ($tenant !== null && $tenant['saas_instance_id'] !== null) {
                    $issues[] = 'mapped_instance_cannot_be_excluded_silently';
                }
            }

            $results[] = [
                'position' => $index + 1,
                'tenant_code' => $code,
                'proposed_kind' => $kind,
                'proposed_establishment_type' => $kind === 'school' ? $type : null,
                'proposed_group_code' => $groupCode !== '' ? $groupCode : null,
                'historical_group_code' => $tenant['legacy_group_code'] ?? null,
                'status' => $issues === [] ? 'pending_human_approval' : 'blocked',
                'issues' => array_values(array_unique($issues)),
            ];
        }

        $blocked = count(array_filter($results, fn (array $row) => $row['status'] === 'blocked'));

        return [
            'ok' => true,
            'mode' => 'read_only_review',
            'can_apply' => false,
            'total' => count($results),
            'blocked' => $blocked,
            'pending_human_approval' => count($results) - $blocked,
            'proposals' => $results,
        ];
    }
}
