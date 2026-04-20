<?php

namespace App\Livewire\Group;

use App\Support\Period\PeriodEventContract;
use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;
use App\Support\Period\PeriodType;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Period selector for the group portal topbar.
 *
 * PR4b scope: UI + URL persistence only. Does NOT dispatch browser events nor
 * mutate any backend cache. Widget wiring is deferred to PR4c where a fixed
 * event contract will be introduced with real consumers.
 *
 * Security: #[Url] properties come from untrusted query strings. All hydration
 * goes through PeriodType::tryFromSafe() and CarbonImmutable::parse() in try/catch
 * so malicious payloads (XSS, SQL-like, oversized strings) fall back to the default.
 */
class PortalPeriodSelector extends Component
{
    #[Url(as: 'period')]
    public string $periodType = '';

    #[Url(as: 'start')]
    public ?string $customStart = null;

    #[Url(as: 'end')]
    public ?string $customEnd = null;

    public function mount(): void
    {
        $this->periodType = PeriodType::tryFromSafe($this->periodType)->value;
    }

    public function updatedPeriodType(string $value): void
    {
        // Re-normalize after user-driven change (defense in depth: Livewire already
        // re-hydrates via #[Url] but we re-validate in case of programmatic sets).
        $this->periodType = PeriodType::tryFromSafe($value)->value;

        $this->dispatchPeriodChange();
    }

    public function updatedCustomStart(): void
    {
        $this->dispatchPeriodChange();
    }

    public function updatedCustomEnd(): void
    {
        $this->dispatchPeriodChange();
    }

    /**
     * Dispatches the frozen period-change event (see PeriodEventContract).
     * Silently skipped when the current selection doesn't resolve to a Period
     * (e.g. custom-range with missing/malformed dates) — the UI remains open
     * without committing an ambiguous range to consumers.
     */
    private function dispatchPeriodChange(): void
    {
        $period = $this->resolvedPeriod;

        if ($period === null) {
            return;
        }

        // Livewire 3 sends named args as event.detail keys — consumers read
        // $event.detail.type, .start, .end, .label on the browser side.
        $this->dispatch(
            PeriodEventContract::EVENT_NAME,
            type: $this->currentType->value,
            start: $period->startDate()->toIso8601String(),
            end: $period->endDate()->toIso8601String(),
            label: $period->label(),
        );
    }

    public function getCurrentTypeProperty(): PeriodType
    {
        return PeriodType::tryFromSafe($this->periodType);
    }

    public function getCurrentLabelProperty(): string
    {
        return $this->currentType->label();
    }

    /**
     * Build the Period value object for the current selection.
     * Returns null when CustomRange is selected but dates are incomplete/invalid —
     * the UI then shows the custom-range picker without committing a range.
     */
    public function getResolvedPeriodProperty(): ?PeriodInterface
    {
        return match ($this->currentType) {
            PeriodType::CurrentMonth => PeriodFactory::make(PeriodFactory::TYPE_CURRENT_MONTH),
            PeriodType::CurrentYear => PeriodFactory::make(PeriodFactory::TYPE_CURRENT_YEAR),
            PeriodType::CustomRange => $this->resolveCustomRange(),
        };
    }

    /**
     * The payload to broadcast as `PeriodEventContract::EVENT_NAME` — consumed by:
     *   1. PHP `$this->dispatch()` in the lifecycle hooks above (Livewire bus)
     *   2. Alpine `commit*()` helpers in the blade view, which re-emit as a true
     *      browser CustomEvent on `window` (belt-and-braces: Livewire 3.x doesn't
     *      always bubble the event to `window.dispatchEvent` depending on version
     *      and event-name shape — colons in the name are sensitive).
     *
     * Returns null when the current selection has no resolvable period
     * (e.g. custom-range with missing dates). Consumers treat null as "no-op".
     *
     * @return array{type: string, start: string, end: string, label: string}|null
     */
    public function getResolvedPayloadProperty(): ?array
    {
        $period = $this->resolvedPeriod;

        if ($period === null) {
            return null;
        }

        return [
            'type' => $this->currentType->value,
            'start' => $period->startDate()->toIso8601String(),
            'end' => $period->endDate()->toIso8601String(),
            'label' => $period->label(),
        ];
    }

    /**
     * Pre-computed payloads for the preset period types (CurrentMonth, CurrentYear).
     * Injected as JSON into the blade so Alpine can dispatch without a server roundtrip.
     * CustomRange payloads are built client-side from user-entered dates (labels are
     * best-effort in JS — the server echo refreshes on Livewire re-render afterwards).
     *
     * @return array<string, array{type: string, start: string, end: string, label: string}>
     */
    public function getPresetPayloadsProperty(): array
    {
        $presets = [];

        foreach ([PeriodType::CurrentMonth, PeriodType::CurrentYear] as $type) {
            $period = PeriodFactory::make($type->value);
            $presets[$type->value] = [
                'type' => $type->value,
                'start' => $period->startDate()->toIso8601String(),
                'end' => $period->endDate()->toIso8601String(),
                'label' => $period->label(),
            ];
        }

        return $presets;
    }

    private function resolveCustomRange(): ?PeriodInterface
    {
        if (!$this->customStart || !$this->customEnd) {
            return null;
        }

        try {
            return PeriodFactory::make(PeriodFactory::TYPE_CUSTOM_RANGE, [
                'start' => CarbonImmutable::parse($this->customStart),
                'end' => CarbonImmutable::parse($this->customEnd),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.group.portal-period-selector', [
            'types' => PeriodType::cases(),
        ]);
    }
}
