<?php

use App\Enums\AlertType;

/**
 * Structural tests for the PR7c unpaid-invoices alert. Full integration
 * with seeded `invoices` + `groups` + `tenants` is covered by the visual
 * check; these tests lock the contract on config, enum, and source patterns.
 */

it('UnpaidInvoices AlertType case is registered', function () {
    expect(AlertType::UnpaidInvoices->value)->toBe('unpaid_invoices');
});

it('unpaid invoices config defaults match the PR7c contract', function () {
    expect((int) config('group_portal.unpaid_invoices_warning_fcfa'))->toBe(200000);
    expect((int) config('group_portal.unpaid_invoices_critical_fcfa'))->toBe(500000);
});

it('TenantAggregationService pre-aggregates invoice balances in one SELECT', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    expect($source)->toContain('loadUnpaidInvoiceBalances');
    // Single grouped query — no cross-DB fan-out, no per-tenant loop over invoices
    expect($source)->toContain("DB::table('invoices')");
    expect($source)->toContain('selectRaw(\'tenant_id, SUM(total_amount - amount_paid) as balance_due\')');
    expect($source)->toContain('groupBy(\'tenant_id\')');
});

it('unpaid invoices query filters to actionable statuses only', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    // paid / draft / cancelled invoices do NOT contribute to balance_due
    expect($source)->toContain("whereIn('status', ['sent', 'overdue'])");
    expect($source)->toContain('whereNull(\'deleted_at\')');
});

it('collectUnpaidInvoicesAlerts skips below warning threshold', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    // Explicit early return when balance < warning threshold
    expect($source)->toContain('if ($balanceDue < $warningThreshold) {');
});

it('collectUnpaidInvoicesAlerts escalates to Critical at the critical threshold', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    $pattern = '/\$balanceDue\s*>=\s*\$criticalThreshold\s*\?\s*AlertSeverity::Critical\s*:\s*AlertSeverity::Warning/';
    expect(preg_match($pattern, $source))->toBe(1);
});

it('unpaid invoices counters are initialised in $health array', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    expect($source)->toContain("'unpaid_invoices_count' => 0");
    expect($source)->toContain("'unpaid_invoices_total_fcfa' => 0");
});

it('unpaid invoices are gated by the health alerts kill switch', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    // loadUnpaidInvoiceBalances is called only inside the $healthAlertsEnabled branch.
    // Regex tolerates whitespace variation.
    $pattern = '/if\s*\(\$healthAlertsEnabled\)\s*\{[^}]*?loadUnpaidInvoiceBalances/s';
    expect(preg_match($pattern, $source))->toBe(1);
});
