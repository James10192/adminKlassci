<?php

use App\Models\Tenant;
use App\Services\StorageIngestionService;

it('parses du -sm output and returns the first numeric field', function () {
    $service = new StorageIngestionService();

    expect($service->parseDuOutput("1234\t/home/c2569688c/public_html/rostan\n"))->toBe(1234);
    expect($service->parseDuOutput("0\t/some/path"))->toBe(0);
    expect($service->parseDuOutput("  987   /padded/path  "))->toBe(987);
});

it('returns null for empty or malformed du output', function () {
    $service = new StorageIngestionService();

    expect($service->parseDuOutput(''))->toBeNull();
    expect($service->parseDuOutput("   \n   "))->toBeNull();
    expect($service->parseDuOutput("not_a_number\t/path"))->toBeNull();
});

it('clamps negative values to zero (defensive against weird du output)', function () {
    $service = new StorageIngestionService();

    expect($service->parseDuOutput("-1\t/path"))->toBe(0);
});

it('builds the SSH command with defensive shell escaping', function () {
    $service = new StorageIngestionService();

    $cmd = $service->buildSshCommand('c2569688c', 'web44.lws-hosting.com', '/home/c2569688c/public_html/rostan');

    expect($cmd)->toContain('ssh ');
    expect($cmd)->toContain('-o BatchMode=yes');
    expect($cmd)->toContain('-o ConnectTimeout=10');
    expect($cmd)->toContain('du -sm');

    // escapeshellarg() uses different quoting on Windows vs POSIX, so assert
    // the raw values appear (quoted in either flavor) rather than hard-coding
    // the quote character.
    expect($cmd)->toContain('c2569688c');
    expect($cmd)->toContain('web44.lws-hosting.com');
    expect($cmd)->toContain('/home/c2569688c/public_html/rostan');
});

it('measureTenantStorageMb returns null when the feature flag is off', function () {
    config()->set('group_portal.storage_ingestion_enabled', false);

    $tenant = new Tenant();
    $tenant->setRawAttributes(['code' => 'test', 'subdomain' => 'test'], true);

    expect(app(StorageIngestionService::class)->measureTenantStorageMb($tenant))->toBeNull();
});

it('measureTenantStorageMb returns null when configuration is incomplete', function () {
    config()->set('group_portal.storage_ingestion_enabled', true);
    config()->set('group_portal.storage_ssh_host', '');
    config()->set('group_portal.storage_ssh_user', 'c2569688c');
    config()->set('group_portal.storage_tenant_base_path', '/home/c2569688c/public_html');

    $tenant = new Tenant();
    $tenant->setRawAttributes(['code' => 'test', 'subdomain' => 'test'], true);

    expect(app(StorageIngestionService::class)->measureTenantStorageMb($tenant))->toBeNull();
});

it('measureTenantStorageMb returns null when tenant has no subdomain', function () {
    config()->set('group_portal.storage_ingestion_enabled', true);
    config()->set('group_portal.storage_ssh_host', 'test.host');
    config()->set('group_portal.storage_ssh_user', 'user');
    config()->set('group_portal.storage_tenant_base_path', '/base');

    $tenant = new Tenant();
    $tenant->setRawAttributes(['code' => 'test', 'subdomain' => null], true);

    expect(app(StorageIngestionService::class)->measureTenantStorageMb($tenant))->toBeNull();
});
