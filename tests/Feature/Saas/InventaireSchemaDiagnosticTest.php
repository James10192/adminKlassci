<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('explique la table absente avant de lire la moindre donnee client', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    Schema::shouldReceive('hasTable')->andReturnUsing(
        fn (string $table) => $table !== 'saas_product_instances'
    );
    Schema::shouldReceive('hasColumn')->andReturn(true);

    $exit = Artisan::call('saas:inventory', ['--format' => 'json']);
    $body = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($body['ok'])->toBeFalse()
        ->and($body['error_code'])->toBe('saas_schema_incomplete')
        ->and($body['missing_tables'])->toContain('saas_product_instances')
        ->and($body['missing_columns'])->toBe([])
        ->and($body['next_step'])->toBe('php artisan migrate:status')
        ->and($queries)->toBe([]);
});

it('diagnostique la migration du pont groupe sans exposer SQL ni secrets', function () {
    Schema::shouldReceive('hasTable')->andReturn(true);
    Schema::shouldReceive('hasColumn')->andReturnUsing(
        fn (string $table, string $column) => ! (
            $table === 'saas_organizations' && $column === 'legacy_group_id'
        )
    );

    $exit = Artisan::call('saas:inventory');
    $out = Artisan::output();

    expect($exit)->toBe(1)
        ->and($out)->toContain('saas_organizations.legacy_group_id')
        ->and($out)->toContain('php artisan migrate:status')
        ->and($out)->not->toContain('SQLSTATE')
        ->and($out)->not->toContain('DB_PASSWORD');
});

it('produit toujours un inventaire valide lorsque le schema est complet', function () {
    $exit = Artisan::call('saas:inventory', ['--format' => 'json']);
    $body = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($body['mode'])->toBe('read_only')
        ->and($body['summary']['legacy_tenants'])->toBe(0);
});
