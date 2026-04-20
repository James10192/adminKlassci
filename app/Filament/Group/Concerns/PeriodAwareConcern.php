<?php

namespace App\Filament\Group\Concerns;

use App\Support\Period\PeriodEventContract;
use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;
use App\Support\Period\PeriodType;
use Carbon\CarbonImmutable;
use Livewire\Attributes\On;

/**
 * Makes a Filament widget react to the frozen group-portal period-change event.
 *
 * Applied to widgets whose metrics are time-windowed (MoM/YoY/aging/trends).
 * Snapshot widgets (enrollment, alerts, etc.) MUST NOT use this concern — their
 * metrics ignore the period by design.
 *
 * ## Mechanism
 *
 * Livewire 3 `#[On(...)]` natively listens to `window` CustomEvents dispatched
 * by the PortalPeriodSelector (via Alpine `window.dispatchEvent()`). No per-widget
 * Alpine bridge is required — the producer dispatches on `window`, the consumers
 * hook the same bus.
 *
 * Note (feedback_livewire3_colons_dispatch_gotcha): the colons gotcha applies to
 * the PRODUCER side (PHP `$this->dispatch()` not always bubbling to `window`).
 * That is already mitigated in PortalPeriodSelector by dispatching via Alpine.
 * On the CONSUMER side, `#[On('name:with:colons')]` works because Livewire
 * wires the listener to `window.addEventListener(...)` directly.
 *
 * ## Feature flag
 *
 * When `group_portal.widgets_period_aware` is OFF, `currentPeriod()` always
 * returns the default period regardless of received events. This lets ops roll
 * the feature out gradually without code changes — flip the env var.
 *
 * ## Graceful degradation
 *
 * Malformed payloads (missing keys, unknown type, unparseable dates) fall back
 * to the default period rather than throwing. The widget stays functional with
 * stale/default data instead of a broken dashboard.
 *
 * @see \App\Support\Period\PeriodEventContract — frozen event contract
 * @see \App\Support\Period\PeriodInterface — period value object
 * @see \App\Livewire\Group\PortalPeriodSelector — producer
 */
trait PeriodAwareConcern
{
    /**
     * Last received payload, or null if no period-change event has arrived yet.
     * Serialized by Livewire across requests — kept as a plain array to avoid
     * re-serializing PeriodInterface (readonly classes don't round-trip cleanly
     * through Livewire's hydration).
     *
     * @var array{type: string, start: string, end: string, label: string}|null
     */
    public ?array $periodPayload = null;

    #[On(PeriodEventContract::EVENT_NAME)]
    public function onPeriodChanged(array $payload): void
    {
        // Defensive copy — only keep the keys we care about.
        // Garbage extras (e.g. from a malicious dispatcher) are dropped silently.
        $clean = [];
        foreach (PeriodEventContract::PAYLOAD_KEYS as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $clean[$key] = $payload[$key];
            }
        }

        if (count($clean) !== count(PeriodEventContract::PAYLOAD_KEYS)) {
            // Missing keys → ignore the event, keep the previous period.
            return;
        }

        $this->periodPayload = $clean;
    }

    protected function currentPeriod(): PeriodInterface
    {
        if (! config('group_portal.widgets_period_aware', false)) {
            return PeriodFactory::default();
        }

        if ($this->periodPayload === null) {
            return PeriodFactory::default();
        }

        try {
            $type = PeriodType::tryFromSafe($this->periodPayload['type']);

            if ($type === PeriodType::CustomRange) {
                return PeriodFactory::make(PeriodFactory::TYPE_CUSTOM_RANGE, [
                    'start' => CarbonImmutable::parse($this->periodPayload['start']),
                    'end' => CarbonImmutable::parse($this->periodPayload['end']),
                ]);
            }

            return PeriodFactory::make($type->value);
        } catch (\Throwable) {
            // Unparseable dates, invalid type — fall back to default.
            return PeriodFactory::default();
        }
    }
}
