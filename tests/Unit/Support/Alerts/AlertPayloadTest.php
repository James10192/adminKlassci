<?php

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Tenant;
use App\Support\Alerts\AlertPayload;

it('constructs from a Tenant via make()', function () {
    $tenant = new Tenant(['code' => 'rostan', 'name' => 'ROSTAN Abidjan']);

    $payload = AlertPayload::make(
        $tenant,
        AlertSeverity::Critical,
        AlertType::QuotaExceeded,
        'Quota users dépassé (105%)',
    );

    expect($payload->severity)->toBe(AlertSeverity::Critical)
        ->and($payload->type)->toBe(AlertType::QuotaExceeded)
        ->and($payload->tenantCode)->toBe('rostan')
        ->and($payload->tenantName)->toBe('ROSTAN Abidjan')
        ->and($payload->message)->toBe('Quota users dépassé (105%)');
});

it('hydrates from a legacy array via from()', function () {
    $legacy = [
        'severity' => 'warning',
        'type' => 'ssl_expiring',
        'tenant_code' => 'yakro',
        'tenant_name' => 'ESBTP Yakro',
        'message' => 'Certificat expire dans 10 jours',
    ];

    $payload = AlertPayload::from($legacy);

    expect($payload->severity)->toBe(AlertSeverity::Warning)
        ->and($payload->type)->toBe(AlertType::SslExpiring)
        ->and($payload->tenantCode)->toBe('yakro')
        ->and($payload->tenantName)->toBe('ESBTP Yakro')
        ->and($payload->message)->toBe('Certificat expire dans 10 jours');
});

it('returns the same instance when from() receives an AlertPayload (fast path)', function () {
    $tenant = new Tenant(['code' => 'rostan', 'name' => 'ROSTAN']);
    $original = AlertPayload::make($tenant, AlertSeverity::Info, AlertType::SubscriptionExpiring, 'hi');

    expect(AlertPayload::from($original))->toBe($original);
});

it('round-trips through toArray and from()', function () {
    $tenant = new Tenant(['code' => 'rostan', 'name' => 'ROSTAN']);
    $original = AlertPayload::make(
        $tenant,
        AlertSeverity::Critical,
        AlertType::UnpaidInvoices,
        '4.6M FCFA impayés depuis >60 jours',
    );

    $roundTrip = AlertPayload::from($original->toArray());

    expect($roundTrip->severity)->toBe($original->severity)
        ->and($roundTrip->type)->toBe($original->type)
        ->and($roundTrip->tenantCode)->toBe($original->tenantCode)
        ->and($roundTrip->tenantName)->toBe($original->tenantName)
        ->and($roundTrip->message)->toBe($original->message);
});

it('falls back to tenant_code when tenant_name is absent in legacy array', function () {
    $payload = AlertPayload::from([
        'severity' => 'info',
        'type' => 'subscription_expiring',
        'tenant_code' => 'rostan',
        'message' => 'expire dans 20j',
    ]);

    expect($payload->tenantName)->toBe('rostan')
        ->and($payload->tenantCode)->toBe('rostan');
});

it('survives Laravel cache serialization (readonly + enum properties)', function () {
    $tenant = new Tenant(['code' => 'rostan', 'name' => 'ROSTAN']);
    $original = AlertPayload::make($tenant, AlertSeverity::Critical, AlertType::QuotaCritical, 'msg');

    $serialized = serialize($original);
    $restored = unserialize($serialized);

    expect($restored)->toBeInstanceOf(AlertPayload::class)
        ->and($restored->severity)->toBe(AlertSeverity::Critical)
        ->and($restored->type)->toBe(AlertType::QuotaCritical);
});
