<?php

use App\Enums\AlertType;

/**
 * Structural tests for the PR7d teacher workload alert. Full integration
 * requires cross-tenant DB fixtures with seeded `esbtp_seance_cours` rows —
 * covered by manual QA post-merge. These tests lock the contract.
 */

it('TeacherOverload AlertType case is registered', function () {
    expect(AlertType::TeacherOverload->value)->toBe('teacher_overload');
});

it('teacher workload config defaults match the PR7d contract', function () {
    expect((int) config('group_portal.teacher_workload_warning_hours'))->toBe(30);
    expect((int) config('group_portal.teacher_workload_critical_hours'))->toBe(40);
});

it('TenantAggregationService wires the TeacherWorkloadResolver', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    expect($source)->toContain('use App\\Services\\Group\\TeacherWorkloadResolver;');
    expect($source)->toContain('protected TeacherWorkloadResolver $workloadResolver');
    expect($source)->toContain('collectTeacherWorkloadAlerts');
    expect($source)->toContain('computeTenantTeacherWorkload');
});

it('teacher workload fan-out uses the aggregator pattern', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    // Same pattern as computeTenantMonthlyEnrollments — aggregator handles
    // connection pooling, error isolation, per-tenant try/catch.
    $pattern = '/aggregator->aggregate\(\s*\$group,\s*self::class,\s*[\'"]computeTenantTeacherWorkload[\'"]/';
    expect(preg_match($pattern, $source))->toBe(1);
});

it('teacher workload query reads esbtp_seance_cours (actual sessions) with enseignant join', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    expect($source)->toContain("'esbtp_seance_cours as s'");
    expect($source)->toContain("leftJoin('users as u', 's.enseignant_id', '=', 'u.id')");
    expect($source)->toContain("whereNotNull('s.enseignant_id')");
    expect($source)->toContain("whereNull('s.deleted_at')");
    // Current academic year only
    expect($source)->toContain("'esbtp_annee_universitaires'");
});

it('teacher workload hours are derived from heure_fin minus heure_debut', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    // Sum-of-durations expressed in seconds, divided by 3600 → hours.
    expect($source)->toContain('TIME_TO_SEC(s.heure_fin) - TIME_TO_SEC(s.heure_debut)');
    expect($source)->toContain('/ 3600.0 as weekly_hours');
});

it('computeTenantTeacherWorkload returns empty shape on connection failure (not thrown)', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    // Silent fallback with Log::error — consistent with sibling tenant methods
    expect($source)->toContain("Log::error(\"[group-refactor] computeTenantTeacherWorkload failed");
    expect($source)->toContain("return \$empty;");
});

it('teacher overload counters are initialised in $health array', function () {
    $source = file_get_contents(app_path('Services/TenantAggregationService.php'));

    expect($source)->toContain("'teacher_overload_count' => 0");
    expect($source)->toContain("'teacher_overload_critical_count' => 0");
});
