<?php

use App\Filament\Group\Concerns\PeriodAwareConcern;
use App\Filament\Group\Widgets\EnrollmentWidget;
use App\Filament\Group\Widgets\EstablishmentCardsWidget;
use App\Filament\Group\Widgets\GroupAgingWidget;
use App\Filament\Group\Widgets\GroupAlertsWidget;
use App\Filament\Group\Widgets\GroupWelcomeWidget;
use App\Filament\Group\Widgets\KpiOverviewWidget;
use App\Filament\Group\Widgets\RevenueComparisonWidget;

/**
 * Structural tests: the PR4e scope locked in that ONLY the 3 time-windowed
 * widgets gain the trait. Migrating a snapshot widget (enrollment, alerts,
 * welcome, cards) by mistake would silently push a broken semantic — these
 * tests catch that at CI time.
 */

it('the 3 time-windowed widgets apply PeriodAwareConcern', function (string $class) {
    expect(in_array(PeriodAwareConcern::class, class_uses_recursive($class), true))
        ->toBeTrue("{$class} must use PeriodAwareConcern");
})->with([
    KpiOverviewWidget::class,
    RevenueComparisonWidget::class,
    GroupAgingWidget::class,
]);

it('snapshot widgets do NOT apply PeriodAwareConcern (scope guard)', function (string $class) {
    expect(in_array(PeriodAwareConcern::class, class_uses_recursive($class), true))
        ->toBeFalse("{$class} must NOT use PeriodAwareConcern — it's a snapshot widget");
})->with([
    EnrollmentWidget::class,
    EstablishmentCardsWidget::class,
    GroupAlertsWidget::class,
    GroupWelcomeWidget::class,
]);

it('KpiOverviewWidget passes the period to all 3 aggregation service calls', function () {
    // Read the source directly — the widget calls service 3 times in getStats().
    // All 3 calls must include $this->currentPeriod() (or a variable holding it)
    // to avoid temporal incoherence between KPIs / trends / aging buckets.
    $source = file_get_contents(
        (new ReflectionClass(KpiOverviewWidget::class))->getFileName()
    );

    // Rough but stable check: 3 service methods called with 2 args each.
    foreach (['getGroupKpis', 'getGroupTrends', 'getGroupOutstandingAging'] as $method) {
        expect($source)->toMatch(
            '/->'.$method.'\(\$group,\s*\$period\)/',
            "KpiOverviewWidget::getStats() must call {$method}(\\\$group, \\\$period)"
        );
    }
});

it('RevenueComparisonWidget passes the period to getGroupFinancials', function () {
    $source = file_get_contents(
        (new ReflectionClass(RevenueComparisonWidget::class))->getFileName()
    );

    expect($source)->toMatch('/->getGroupFinancials\(\$group,\s*\$this->currentPeriod\(\)\)/');
});

it('GroupAgingWidget passes the period to getGroupOutstandingAging', function () {
    $source = file_get_contents(
        (new ReflectionClass(GroupAgingWidget::class))->getFileName()
    );

    expect($source)->toMatch('/->getGroupOutstandingAging\(\$group,\s*\$this->currentPeriod\(\)\)/');
});

it('feature flag defaults to OFF for safe rollout', function () {
    // Don't read .env — read the config default directly. Ops must opt IN.
    $configPath = config_path('group_portal.php');
    $config = require $configPath;

    expect($config)->toHaveKey('widgets_period_aware');
    // The env() default (2nd arg) must be false.
    $rawConfigSource = file_get_contents($configPath);
    expect($rawConfigSource)->toContain("env('GROUP_PORTAL_WIDGETS_PERIOD_AWARE', false)");
});
