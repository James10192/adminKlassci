<?php

use App\Services\Group\AlertFingerprintGenerator;

it('generates the same fingerprint for the same (group, tenant, type, severity) tuple', function () {
    $generator = new AlertFingerprintGenerator();

    $alert = ['tenant_code' => 'rostan', 'type' => 'ssl_expiring', 'severity' => 'critical'];

    $fp1 = $generator->generate(1, $alert);
    $fp2 = $generator->generate(1, $alert);

    expect($fp1)->toBe($fp2);
    expect($fp1)->toBe(hash('sha256', '1|rostan|ssl_expiring|critical'));
});

it('changes fingerprint when severity escalates (so re-notify fires)', function () {
    $generator = new AlertFingerprintGenerator();

    $warning = ['tenant_code' => 'rostan', 'type' => 'quota_critical', 'severity' => 'warning'];
    $critical = ['tenant_code' => 'rostan', 'type' => 'quota_critical', 'severity' => 'critical'];

    expect($generator->generate(1, $warning))->not->toBe($generator->generate(1, $critical));
});

it('changes fingerprint when tenant differs (per-tenant dedup)', function () {
    $generator = new AlertFingerprintGenerator();

    $tenantA = ['tenant_code' => 'rostan', 'type' => 'ssl_expiring', 'severity' => 'critical'];
    $tenantB = ['tenant_code' => 'yakro', 'type' => 'ssl_expiring', 'severity' => 'critical'];

    expect($generator->generate(1, $tenantA))->not->toBe($generator->generate(1, $tenantB));
});

it('changes fingerprint when group differs (cross-group isolation)', function () {
    $generator = new AlertFingerprintGenerator();

    $alert = ['tenant_code' => 'rostan', 'type' => 'ssl_expiring', 'severity' => 'critical'];

    expect($generator->generate(1, $alert))->not->toBe($generator->generate(2, $alert));
});

it('handles missing keys without throwing (defensive against upstream drift)', function () {
    $generator = new AlertFingerprintGenerator();

    $degraded = ['severity' => 'warning'];  // no tenant_code, no type

    $fp = $generator->generate(1, $degraded);
    expect($fp)->toBeString();
    expect(strlen($fp))->toBe(64);  // sha256 hex
});
