<?php

use App\Filament\Group\Concerns\PeriodAwareConcern;
use App\Support\Period\CurrentMonthPeriod;
use App\Support\Period\CurrentYearPeriod;
use App\Support\Period\CustomRangePeriod;
use App\Support\Period\PeriodEventContract;
use App\Support\Period\PeriodFactory;
use App\Support\Period\PeriodInterface;
use Livewire\Component;
use Livewire\Livewire;

/**
 * Minimal Livewire component that exercises the PeriodAwareConcern trait in
 * isolation — no Filament widget parents, no auth, no service container calls.
 * Tests assert the trait's observable behaviour, not the full dashboard wiring.
 */
class FakePeriodAwareWidget extends Component
{
    use PeriodAwareConcern;

    public function render(): string
    {
        return '<div>'.$this->currentPeriod()->cacheKey().'</div>';
    }

    public function getPeriodForAssertions(): PeriodInterface
    {
        return $this->currentPeriod();
    }
}

beforeEach(function () {
    // Default-ON for trait behaviour tests — flag-OFF tested separately.
    config(['group_portal.widgets_period_aware' => true]);
});

it('ignores events when feature flag is OFF', function () {
    config(['group_portal.widgets_period_aware' => false]);

    $component = Livewire::test(FakePeriodAwareWidget::class)
        ->call('onPeriodChanged', [
            'type' => 'current-month',
            'start' => '2026-04-01T00:00:00+00:00',
            'end' => '2026-04-30T23:59:59+00:00',
            'label' => 'Avril 2026',
        ]);

    // Flag OFF → currentPeriod() always returns default, regardless of payload.
    $period = $component->instance()->getPeriodForAssertions();
    expect($period->cacheKey())->toBe(PeriodFactory::default()->cacheKey());
});

it('returns default period when no event has been received', function () {
    $component = Livewire::test(FakePeriodAwareWidget::class);

    $period = $component->instance()->getPeriodForAssertions();
    expect($period)->toBeInstanceOf(CurrentYearPeriod::class);
});

it('updates currentPeriod() when a valid current-month payload arrives', function () {
    $component = Livewire::test(FakePeriodAwareWidget::class)
        ->call('onPeriodChanged', [
            'type' => 'current-month',
            'start' => '2026-04-01T00:00:00+00:00',
            'end' => '2026-04-30T23:59:59+00:00',
            'label' => 'Avril 2026',
        ]);

    $period = $component->instance()->getPeriodForAssertions();
    expect($period)->toBeInstanceOf(CurrentMonthPeriod::class);
});

it('updates currentPeriod() when a valid custom-range payload arrives', function () {
    $component = Livewire::test(FakePeriodAwareWidget::class)
        ->call('onPeriodChanged', [
            'type' => 'custom-range',
            'start' => '2026-01-15T00:00:00+00:00',
            'end' => '2026-03-20T23:59:59+00:00',
            'label' => '15/01/2026 → 20/03/2026',
        ]);

    $period = $component->instance()->getPeriodForAssertions();
    expect($period)->toBeInstanceOf(CustomRangePeriod::class);
    expect($period->startDate()->format('Y-m-d'))->toBe('2026-01-15');
    expect($period->endDate()->format('Y-m-d'))->toBe('2026-03-20');
});

it('falls back to default when payload is missing required keys', function () {
    $component = Livewire::test(FakePeriodAwareWidget::class)
        ->call('onPeriodChanged', [
            'type' => 'current-month',
            // missing start, end, label
        ]);

    // Payload rejected → currentPeriod() still returns default.
    $period = $component->instance()->getPeriodForAssertions();
    expect($period->cacheKey())->toBe(PeriodFactory::default()->cacheKey());
});

it('falls back to default when payload contains unknown type', function () {
    $component = Livewire::test(FakePeriodAwareWidget::class)
        ->call('onPeriodChanged', [
            'type' => 'no-such-type',
            'start' => '2026-01-01T00:00:00+00:00',
            'end' => '2026-12-31T23:59:59+00:00',
            'label' => 'Garbage',
        ]);

    // tryFromSafe() falls back to default — currentPeriod returns default year.
    $period = $component->instance()->getPeriodForAssertions();
    expect($period)->toBeInstanceOf(CurrentYearPeriod::class);
});

it('falls back to default when custom-range dates are unparseable', function () {
    $component = Livewire::test(FakePeriodAwareWidget::class)
        ->call('onPeriodChanged', [
            'type' => 'custom-range',
            'start' => 'not-a-date',
            'end' => '<xss>',
            'label' => 'Malicious',
        ]);

    $period = $component->instance()->getPeriodForAssertions();
    expect($period)->toBeInstanceOf(CurrentYearPeriod::class);
});

it('drops extra keys in the payload (defensive against payload contamination)', function () {
    $component = Livewire::test(FakePeriodAwareWidget::class)
        ->call('onPeriodChanged', [
            'type' => 'current-year',
            'start' => '2026-01-01T00:00:00+00:00',
            'end' => '2026-12-31T23:59:59+00:00',
            'label' => 'Année 2026',
            'cacheKey' => 'ATTACKER_CONTROLLED_KEY',
            'sql' => "'; DROP TABLE tenants; --",
        ]);

    // The trait must NOT expose cacheKey or other extras — only the 4 contract keys.
    $payload = $component->get('periodPayload');
    expect(array_keys($payload))->toBe(PeriodEventContract::PAYLOAD_KEYS);
});

it('ignores non-string values in payload keys (type confusion protection)', function () {
    $component = Livewire::test(FakePeriodAwareWidget::class)
        ->call('onPeriodChanged', [
            'type' => ['nested', 'array'],
            'start' => 42,
            'end' => null,
            'label' => 'valid',
        ]);

    // Count mismatch → payload rejected entirely, default period remains.
    $period = $component->instance()->getPeriodForAssertions();
    expect($period->cacheKey())->toBe(PeriodFactory::default()->cacheKey());
});

it('registers the Livewire listener on the frozen event name', function () {
    // Meta-test: the trait must listen to the frozen contract name, not a custom one.
    $reflection = new ReflectionClass(FakePeriodAwareWidget::class);
    $method = $reflection->getMethod('onPeriodChanged');
    $attributes = $method->getAttributes(\Livewire\Attributes\On::class);

    expect($attributes)->toHaveCount(1);
    expect($attributes[0]->getArguments()[0])->toBe(PeriodEventContract::EVENT_NAME);
});
