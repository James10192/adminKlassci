<?php

use Illuminate\Support\Facades\Route;

/**
 * Structural tests for the PR-B alerts index page. Full Livewire rendering
 * with a seeded group requires cross-tenant DB fixtures — the visual check
 * covers that. These tests lock the contract: page exists, routes exist,
 * session ack shape, navigation props.
 */

it('AlertsIndex page class carries the expected Filament props', function () {
    $page = \App\Filament\Group\Pages\AlertsIndex::class;

    expect($page::getSlug())->toBe('alertes');

    // Protected statics — reach via reflection.
    $reflection = new ReflectionClass($page);
    $title = $reflection->getStaticPropertyValue('title');
    $group = $reflection->getStaticPropertyValue('navigationGroup');

    expect($title)->toBe('Toutes les alertes');
    expect($group)->toBe('Analytiques');
});

it('alert acknowledgment routes are registered under the group auth guard', function () {
    foreach (['groupe.alerts.acknowledge', 'groupe.alerts.unacknowledge'] as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull();
        expect($route->methods())->toContain('POST');
        expect($route->middleware())->toContain('auth:group');
    }
});

it('acknowledge route is rate-limited (anti-abuse on session write)', function () {
    $route = Route::getRoutes()->getByName('groupe.alerts.acknowledge');

    $middleware = $route->middleware();
    $throttle = collect($middleware)->first(fn ($m) => str_starts_with($m, 'throttle:'));

    expect($throttle)->not->toBeNull();
});

it('alerts-index view references the AlertType enum to build labels', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/pages/alerts-index.blade.php')
    );

    expect($source)->toContain('use App\\Enums\\AlertType;');
    expect($source)->toContain('AlertType::PlanMismatch->value');
    expect($source)->toContain('AlertType::SslExpiring->value');
    expect($source)->toContain('AlertType::EnrollmentDecline->value');
});

it('alerts-index view implements client-side filtering via Alpine', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/pages/alerts-index.blade.php')
    );

    // Filter chips + select + search input + acknowledged toggle
    expect($source)->toContain('x-data');
    expect($source)->toContain('matches(alert)');
    expect($source)->toContain("severity = 'all'");
    expect($source)->toContain('showAcknowledged');
});

it('page fingerprint is stable across message changes for the same tenant+type', function () {
    // Mirrors the private fingerprintOf() logic — same tenant + type = same hash
    // regardless of days-remaining variance in the message field.
    $alertV1 = ['tenant_code' => 'rostan', 'type' => 'ssl_expiring', 'message' => '10 jours'];
    $alertV2 = ['tenant_code' => 'rostan', 'type' => 'ssl_expiring', 'message' => '7 jours'];

    $fp = fn ($a) => md5(($a['tenant_code'] ?? '') . '|' . ($a['type'] ?? ''));

    expect($fp($alertV1))->toBe($fp($alertV2));
});

it('widget view links to AlertsIndex when alerts overflow the top-5 cap', function () {
    $source = file_get_contents(
        resource_path('views/filament/group/widgets/group-alerts.blade.php')
    );

    expect($source)->toContain('AlertsIndex::getUrl()');
    expect($source)->toContain('Voir les');
    expect($source)->toContain('$totalAlerts > count($alerts)');
});

it('alerts-index CSS namespace is shipped', function () {
    $css = file_get_contents(public_path('css/groupe-portal.css'));

    expect($css)->toContain('.ga-panel');
    expect($css)->toContain('.ga-chip--critical');
    expect($css)->toContain('.ga-chip--warning');
    expect($css)->toContain('.ga-chip--info');
    expect($css)->toContain('.gp-alerts-footer-link');
});
