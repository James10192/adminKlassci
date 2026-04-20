<?php

namespace App\Filament\Group\Concerns;

use App\Support\Period\PeriodEventContract;
use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * Makes a Filament widget react to the frozen group-portal period-change event.
 *
 * Applied to widgets whose metrics are time-windowed (MoM/YoY/aging/trends).
 * Snapshot widgets MUST NOT use this concern — their metrics ignore the period.
 *
 * When `group_portal.widgets_period_aware` is OFF, `currentPeriod()` always
 * returns the default period regardless of received events — lets ops roll
 * the feature out gradually without code changes.
 *
 * @see \App\Support\Period\PeriodEventContract — frozen event contract
 */
trait PeriodAwareConcern
{
    /**
     * Kept as plain array (not PeriodInterface) because Livewire can't
     * round-trip readonly value objects through hydration cleanly.
     *
     * `#[Locked]` prevents client-side mutation via `$wire.set()` — the only
     * write path is `onPeriodChanged()`, which sanitizes before assigning.
     *
     * @var array{type: string, start: string, end: string, label: string}|null
     */
    #[Locked]
    public ?array $periodPayload = null;

    #[On(PeriodEventContract::EVENT_NAME)]
    public function onPeriodChanged(array $payload): void
    {
        $clean = [];
        foreach (PeriodEventContract::PAYLOAD_KEYS as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key])) {
                return;
            }
            $clean[$key] = $payload[$key];
        }

        $this->periodPayload = $clean;
    }

    protected function currentPeriod(): PeriodInterface
    {
        if (! config('group_portal.widgets_period_aware')) {
            return PeriodFactory::default();
        }

        return PeriodFactory::fromPayload($this->periodPayload);
    }
}
