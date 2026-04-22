<?php

namespace App\Support\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Tenant;

/**
 * Typed snapshot of one alert emitted by the aggregation pipeline.
 *
 * Replaces the ad-hoc `array $alert` passed between TenantAggregationService,
 * AlertFingerprintGenerator, AlertRoleMatcher, AlertNotificationDispatcher,
 * GroupAlertNotificationLog, and the Mailables. The shape is enforced at the
 * type system so the six scattered `?? 'unknown'` / `?? ''` null-coalesces
 * disappear — if a field could ever be null, the DTO says so explicitly.
 *
 * `from()` accepts either an AlertPayload or the legacy array shape, so data
 * that was cached under the old format before a deploy keeps working until
 * the aggregation TTL flushes it (5 min).
 */
final readonly class AlertPayload
{
    public function __construct(
        public AlertSeverity $severity,
        public AlertType $type,
        public string $tenantName,
        public ?string $tenantCode,
        public string $message,
    ) {
    }

    public static function make(
        Tenant $tenant,
        AlertSeverity $severity,
        AlertType $type,
        string $message,
    ): self {
        return new self(
            severity: $severity,
            type: $type,
            tenantName: $tenant->name,
            tenantCode: $tenant->code,
            message: $message,
        );
    }

    /**
     * Accepts either an AlertPayload (fast path) or the legacy array shape
     * (cache compat during rollout). Returning the object type unconditionally
     * lets consumers drop array-vs-object branching.
     *
     * @param  self|array{severity: string, type: string, tenant_name?: string, tenant_code?: string|null, message: string}  $alert
     */
    public static function from(self|array $alert): self
    {
        if ($alert instanceof self) {
            return $alert;
        }

        return new self(
            severity: AlertSeverity::from($alert['severity']),
            type: AlertType::from($alert['type']),
            tenantName: $alert['tenant_name'] ?? ($alert['tenant_code'] ?? ''),
            tenantCode: $alert['tenant_code'] ?? null,
            message: $alert['message'] ?? '',
        );
    }

    /**
     * Array form kept for Blade partials and tests that still read the
     * dictionary shape. Keys match the historical $alert array exactly so
     * the migration is a no-op for legacy consumers.
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'tenant_code' => $this->tenantCode,
            'tenant_name' => $this->tenantName,
            'type' => $this->type->value,
            'message' => $this->message,
        ];
    }
}
