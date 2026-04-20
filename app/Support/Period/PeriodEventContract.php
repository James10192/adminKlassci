<?php

namespace App\Support\Period;

/**
 * Frozen contract for the group portal period-change event.
 *
 * ## Event name
 *
 * Namespaced to avoid collisions with other packages / Livewire-internal events:
 *
 *     klassci:group-portal:period-change
 *
 * ## Payload
 *
 * Minimal by design — no backend internals leak to consumers.
 *
 *     {
 *         "type":  "current-year" | "current-month" | "custom-range",
 *         "start": "2026-01-01T00:00:00+00:00",   // ISO 8601 (CarbonImmutable::toIso8601String)
 *         "end":   "2026-12-31T23:59:59+00:00",   // ISO 8601
 *         "label": "Année 2026"                    // human-readable, fr_FR, from PeriodInterface::label()
 *     }
 *
 * `cacheKey` is deliberately OMITTED (backend derives it server-side from
 * the Period value object — consumers must never reconstruct it).
 *
 * ## How to subscribe (Livewire widget)
 *
 *     <div x-data
 *          @klassci:group-portal:period-change.window="$wire.onPeriodChanged($event.detail)"
 *     >
 *         {{-- widget content --}}
 *     </div>
 *
 *     // PHP
 *     public function onPeriodChanged(array $payload): void
 *     {
 *         $type  = $payload['type']  ?? null;
 *         $start = $payload['start'] ?? null;
 *         $end   = $payload['end']   ?? null;
 *         // Always validate with PeriodType::tryFromSafe + CarbonImmutable::parse in try/catch.
 *     }
 *
 * ## Invariants
 *
 * - The event is dispatched ONLY when the feature flag
 *   `group_portal.period_selector_enabled` is ON (if the selector doesn't render,
 *   it doesn't dispatch).
 * - No dispatch when the user picks `custom-range` but the dates are incomplete
 *   or unparseable — the UI shows the picker without committing.
 * - `start` is always <= `end`; single-day ranges are legal (start == end).
 *
 * @see \App\Livewire\Group\PortalPeriodSelector — producer
 * @see \App\Support\Period\PeriodInterface — backend value object (start/end/label)
 * @see docs/GROUP_PORTAL_EVENT_CONTRACT.md — full spec + subscriber examples
 */
final class PeriodEventContract
{
    public const EVENT_NAME = 'klassci:group-portal:period-change';

    /**
     * Ordered list of required keys in the payload. Consumers can rely on all
     * keys being present — defensive `?? null` on reads is still encouraged.
     *
     * @var list<string>
     */
    public const PAYLOAD_KEYS = ['type', 'start', 'end', 'label'];
}
