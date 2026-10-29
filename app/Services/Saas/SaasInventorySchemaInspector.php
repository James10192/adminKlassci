<?php

namespace App\Services\Saas;

use Illuminate\Support\Facades\Schema;

/**
 * Verifie uniquement les metadonnees des tables du master.
 * Aucun SELECT de donnees et aucune operation DDL/DML.
 */
final class SaasInventorySchemaInspector
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'groups' => ['id', 'code', 'name', 'deleted_at'],
        'tenants' => ['id', 'code', 'name', 'group_id', 'status', 'plan', 'deleted_at'],
        'saas_organizations' => ['id', 'name', 'legacy_group_id'],
        'saas_products' => ['id', 'code', 'establishment_type', 'adapter_key'],
        'saas_establishments' => ['id', 'organization_id', 'product_id', 'name', 'slug'],
        'saas_product_instances' => [
            'id', 'establishment_id', 'product_id', 'legacy_tenant_id',
            'external_tenant_code', 'status',
        ],
    ];

    /**
     * @return array{ready: bool, missing_tables: list<string>, missing_columns: list<string>}
     */
    public function inspect(): array
    {
        $missingTables = [];
        $missingColumns = [];

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missingTables[] = $table;

                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumns[] = $table.'.'.$column;
                }
            }
        }

        return [
            'ready' => $missingTables === [] && $missingColumns === [],
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
        ];
    }
}
