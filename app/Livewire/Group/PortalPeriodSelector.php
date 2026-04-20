<?php

namespace App\Livewire\Group;

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
