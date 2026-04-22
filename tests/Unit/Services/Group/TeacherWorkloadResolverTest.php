<?php

use App\Enums\AlertSeverity;
use App\Services\Group\TeacherWorkloadResolver;

beforeEach(function () {
    config()->set('group_portal.teacher_workload_warning_hours', 30);
    config()->set('group_portal.teacher_workload_critical_hours', 40);
});

it('returns null when every teacher is below the warning threshold', function () {
    $resolver = new TeacherWorkloadResolver();

    $result = $resolver->resolve([
        1 => ['name' => 'Alice', 'hours' => 20],
        2 => ['name' => 'Bob', 'hours' => 29.5],
    ]);

    expect($result)->toBeNull();
});

it('returns null on an empty input set', function () {
    $resolver = new TeacherWorkloadResolver();

    expect($resolver->resolve([]))->toBeNull();
});

it('flags warning when teachers exceed 30h but stay below 40h', function () {
    $resolver = new TeacherWorkloadResolver();

    $result = $resolver->resolve([
        1 => ['name' => 'Alice', 'hours' => 32],
        2 => ['name' => 'Bob', 'hours' => 20],
        3 => ['name' => 'Chantal', 'hours' => 35],
    ]);

    expect($result)->not->toBeNull();
    expect($result['severity'])->toBe(AlertSeverity::Warning);
    expect($result['overloaded_count'])->toBe(2);
    expect($result['critical_count'])->toBe(0);
    expect($result['worst_name'])->toBe('Chantal');
    expect($result['worst_hours'])->toBe(35.0);
});

it('escalates to Critical when any teacher crosses the critical threshold', function () {
    $resolver = new TeacherWorkloadResolver();

    $result = $resolver->resolve([
        1 => ['name' => 'Alice', 'hours' => 32],    // warning
        2 => ['name' => 'Bob', 'hours' => 42],      // critical
    ]);

    expect($result['severity'])->toBe(AlertSeverity::Critical);
    expect($result['overloaded_count'])->toBe(2);
    expect($result['critical_count'])->toBe(1);
    expect($result['worst_name'])->toBe('Bob');
});

it('ranks teachers by hours desc so the worst drives the message', function () {
    $resolver = new TeacherWorkloadResolver();

    $result = $resolver->resolve([
        1 => ['name' => 'Alice', 'hours' => 38],
        2 => ['name' => 'Bob', 'hours' => 35],
        3 => ['name' => 'Chantal', 'hours' => 33],
    ]);

    expect($result['worst_name'])->toBe('Alice');
    expect($result['worst_hours'])->toBe(38.0);
});

it('honours overridden thresholds from config', function () {
    config()->set('group_portal.teacher_workload_warning_hours', 20);
    config()->set('group_portal.teacher_workload_critical_hours', 25);

    $resolver = new TeacherWorkloadResolver();

    $result = $resolver->resolve([
        1 => ['name' => 'Alice', 'hours' => 22],  // warning under overridden threshold
        2 => ['name' => 'Bob', 'hours' => 28],    // critical under overridden threshold
    ]);

    expect($result['severity'])->toBe(AlertSeverity::Critical);
    expect($result['overloaded_count'])->toBe(2);
});

it('falls back to a synthetic name when none is provided', function () {
    $resolver = new TeacherWorkloadResolver();

    $result = $resolver->resolve([
        42 => ['hours' => 35],  // no name key
    ]);

    expect($result['worst_name'])->toBe('Enseignant #42');
});
