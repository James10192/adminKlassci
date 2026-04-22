<?php

namespace App\Services\Group;

use App\Enums\AlertSeverity;

/**
 * Classifies a tenant's teacher-hour distribution into group-portal alert
 * tiers. Pure: consumes a pre-computed `[enseignant_id => hours_per_week]`
 * map and returns the worst-case verdict. The caller (TenantAggregationService
 * on the adminKlassci side) is responsible for the actual DB query — same
 * separation as SubscriptionTierResolver and HealthCheckAlertResolver.
 */
class TeacherWorkloadResolver
{
    /**
     * @param  array<int, array{name: string, hours: float}>  $teacherHours
     *         Pre-fetched per-teacher totals for the current academic year.
     *         Name included so the caller can build precise messages without
     *         a second lookup.
     *
     * @return array{severity: AlertSeverity, overloaded_count: int, critical_count: int, worst_name: string, worst_hours: float}|null
     *         Null when no teacher exceeds the warning threshold.
     */
    public function resolve(array $teacherHours): ?array
    {
        $warningThreshold = (float) config('group_portal.teacher_workload_warning_hours', 30);
        $criticalThreshold = (float) config('group_portal.teacher_workload_critical_hours', 40);

        $overloaded = [];
        $critical = [];

        foreach ($teacherHours as $teacherId => $data) {
            $hours = (float) ($data['hours'] ?? 0);
            if ($hours < $warningThreshold) {
                continue;
            }

            $entry = [
                'name' => (string) ($data['name'] ?? "Enseignant #{$teacherId}"),
                'hours' => $hours,
            ];

            $overloaded[] = $entry;
            if ($hours >= $criticalThreshold) {
                $critical[] = $entry;
            }
        }

        if (empty($overloaded)) {
            return null;
        }

        // Sort desc by hours so the worst teacher drives the message.
        usort($overloaded, fn ($a, $b) => $b['hours'] <=> $a['hours']);
        $worst = $overloaded[0];

        return [
            'severity' => ! empty($critical) ? AlertSeverity::Critical : AlertSeverity::Warning,
            'overloaded_count' => count($overloaded),
            'critical_count' => count($critical),
            'worst_name' => $worst['name'],
            'worst_hours' => $worst['hours'],
        ];
    }
}
