<?php

use Illuminate\Support\Facades\Artisan;

it('tenant:update-storage respects the kill switch', function () {
    config()->set('group_portal.storage_ingestion_enabled', false);

    $exit = Artisan::call('tenant:update-storage');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('storage_ingestion_enabled is false');
});

it('storage ingestion config keys ship with safe defaults', function () {
    expect(config('group_portal.storage_ingestion_enabled'))->toBeFalse();
    expect(config('group_portal.storage_ssh_timeout_sec'))->toBe(30);
});

it('storage ingestion command class is registered', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('tenant:update-storage');
});

it('scheduler wires tenant:update-storage behind the feature flag', function () {
    $source = file_get_contents(base_path('routes/console.php'));

    expect($source)->toContain("Schedule::command('tenant:update-storage')");
    expect($source)->toContain("dailyAt('03:30')");
    expect($source)->toContain("config('group_portal.storage_ingestion_enabled'");
});
