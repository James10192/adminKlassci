<?php

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Services\Group\AlertFingerprintGenerator;
use App\Support\Alerts\AlertPayload;

function makePayload(string $tenantCode, AlertType $type, AlertSeverity $severity): AlertPayload
{
    return new AlertPayload(
        severity: $severity,
        type: $type,
        tenantName: $tenantCode,
        tenantCode: $tenantCode,
        message: 'test',
    );
}

it('generates the same fingerprint for the same (group, tenant, type, severity) tuple', function () {
    $generator = new AlertFingerprintGenerator();

    $alert = makePayload('rostan', AlertType::SslExpiring, AlertSeverity::Critical);

    $fp1 = $generator->generate(1, $alert);
    $fp2 = $generator->generate(1, $alert);

    expect($fp1)->toBe($fp2);
    expect($fp1)->toBe(hash('sha256', '1|rostan|ssl_expiring|critical'));
});

it('changes fingerprint when severity escalates (so re-notify fires)', function () {
    $generator = new AlertFingerprintGenerator();

    $warning = makePayload('rostan', AlertType::QuotaCritical, AlertSeverity::Warning);
    $critical = makePayload('rostan', AlertType::QuotaCritical, AlertSeverity::Critical);

    expect($generator->generate(1, $warning))->not->toBe($generator->generate(1, $critical));
});

it('changes fingerprint when tenant differs (per-tenant dedup)', function () {
    $generator = new AlertFingerprintGenerator();

    $tenantA = makePayload('rostan', AlertType::SslExpiring, AlertSeverity::Critical);
    $tenantB = makePayload('yakro', AlertType::SslExpiring, AlertSeverity::Critical);

    expect($generator->generate(1, $tenantA))->not->toBe($generator->generate(1, $tenantB));
});

it('changes fingerprint when group differs (cross-group isolation)', function () {
    $generator = new AlertFingerprintGenerator();

    $alert = makePayload('rostan', AlertType::SslExpiring, AlertSeverity::Critical);

    expect($generator->generate(1, $alert))->not->toBe($generator->generate(2, $alert));
});

it('hydrates fingerprint from legacy array shape via AlertPayload::from', function () {
    $generator = new AlertFingerprintGenerator();

    $legacyArray = [
        'tenant_code' => 'rostan',
        'tenant_name' => 'ROSTAN Abidjan',
        'type' => 'ssl_expiring',
        'severity' => 'critical',
        'message' => 'SSL expire dans 3 jours',
    ];

    $payload = AlertPayload::from($legacyArray);
    $direct = makePayload('rostan', AlertType::SslExpiring, AlertSeverity::Critical);

    expect($generator->generate(1, $payload))->toBe($generator->generate(1, $direct));
});
