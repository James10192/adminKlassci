# Group Portal — Event Contract

Public, frozen contract for the period-change event emitted by the group portal.

**Consumers MUST read this doc** before subscribing; the shape is part of the public API and will be versioned if breaking changes are required.

---

## Single source of truth

The event contract is defined as PHP constants in:

    app/Support/Period/PeriodEventContract.php

Any code path that dispatches or listens MUST import those constants — no magic strings.

## Event name

    klassci:group-portal:period-change

Namespaced to avoid collisions with Livewire-internal events and other Filament plugins.

## Payload

Livewire 3 dispatches named arguments as `event.detail` keys:

```json
{
  "type":  "current-year",
  "start": "2026-01-01T00:00:00+00:00",
  "end":   "2026-12-31T23:59:59+00:00",
  "label": "Année 2026"
}
```

### Fields

| Key     | Type                   | Notes |
|---------|------------------------|-------|
| `type`  | `'current-month' \| 'current-year' \| 'custom-range'` | Matches `PeriodType` enum backing values |
| `start` | ISO 8601 string        | `CarbonImmutable::toIso8601String()` — always `<= end`, always at `00:00:00` when normalized |
| `end`   | ISO 8601 string        | Always `>= start`, at `23:59:59` when normalized |
| `label` | string (French)        | Human-readable, from `PeriodInterface::label()` — safe to render directly in UI |

### NOT in the payload

- **`cacheKey`** — backend implementation detail. Consumers MUST NOT reconstruct cache keys; call the server-side provider with a `PeriodInterface` instance and let it derive the key.
- **`timezone`** — always normalized through `CarbonImmutable`, same convention throughout the app.

## When is it dispatched

The selector dispatches ONLY when:

1. Feature flag is ON: `config('group_portal.period_selector_enabled')` — if OFF, the selector doesn't render at all.
2. The resolved `PeriodInterface` is non-null — custom-range with missing or unparseable dates does NOT dispatch.
3. A Livewire lifecycle hook fires: `updatedPeriodType`, `updatedCustomStart`, `updatedCustomEnd`. Mounting from a URL query string does NOT dispatch (the page-load is the initial state).

## How to subscribe — Livewire widget

```blade
<div
    x-data
    @klassci:group-portal:period-change.window="$wire.onPeriodChanged($event.detail)"
>
    {{ $this->render() }}
</div>
```

```php
use App\Support\Period\PeriodEventContract;
use App\Support\Period\PeriodType;
use Carbon\CarbonImmutable;

class MyPeriodAwareWidget extends Widget
{
    public ?PeriodType $type = null;
    public ?CarbonImmutable $start = null;
    public ?CarbonImmutable $end = null;

    public function onPeriodChanged(array $payload): void
    {
        $this->type = PeriodType::tryFromSafe($payload['type'] ?? null);

        try {
            $this->start = isset($payload['start'])
                ? CarbonImmutable::parse($payload['start'])
                : null;
            $this->end = isset($payload['end'])
                ? CarbonImmutable::parse($payload['end'])
                : null;
        } catch (\Throwable) {
            // Malformed ISO → log + fall back to default.
            logger()->warning('[group-portal] malformed period payload', $payload);
            $this->type = PeriodType::default();
            $this->start = null;
            $this->end = null;
        }

        // Trigger recompute of getStats() / $this->dispatch('refresh') etc.
    }
}
```

## How to subscribe — vanilla JS / Alpine

```js
window.addEventListener('klassci:group-portal:period-change', (event) => {
    const { type, start, end, label } = event.detail;
    // Update your local state / re-fetch data.
});
```

## Testing subscribers

```php
use App\Livewire\Group\PortalPeriodSelector;
use App\Support\Period\PeriodEventContract;
use Livewire\Livewire;

it('widget recomputes on period change', function () {
    Livewire::test(MyWidget::class)
        ->dispatch(PeriodEventContract::EVENT_NAME,
            type: 'current-month',
            start: '2026-04-01T00:00:00+00:00',
            end: '2026-04-30T23:59:59+00:00',
            label: 'Avril 2026',
        )
        ->assertSet('type', \App\Support\Period\PeriodType::CurrentMonth);
});
```

## Versioning

This contract is **frozen at PR4c**. Any breaking change (new required payload key, renamed key, semantics change) MUST:

1. Introduce a new namespaced event (e.g. `klassci:group-portal:period-change:v2`)
2. Keep the old event firing in parallel for at least one release cycle
3. Deprecate old event name in this file with a migration note
4. Remove only after all known consumers have migrated

Adding NEW keys to the existing payload is acceptable (consumers read with `?? null`), but should still be documented in this file.

## Related

- `app/Support/Period/PeriodEventContract.php` — PHP constants (import here)
- `app/Support/Period/PeriodInterface.php` — backend value object (derive `cacheKey()` server-side)
- `app/Livewire/Group/PortalPeriodSelector.php` — producer
- `docs/PORTAIL_GROUPE_DEPLOIEMENT.md` — deployment runbook
