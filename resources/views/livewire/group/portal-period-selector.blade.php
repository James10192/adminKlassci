@php
    $listboxId = 'gp-period-listbox-' . $this->getId();
    $panelId = 'gp-period-custom-panel-' . $this->getId();
    $currentValue = $this->currentType->value;
    $debounceMs = (int) config('group_portal.period_selector_debounce_ms', 300);
@endphp

<div
    class="gp-period-selector"
    x-data="{
        open: false,
        customOpen: @js($currentValue === 'custom-range'),
        localType: @js($currentValue),
        localStart: @js($customStart),
        localEnd: @js($customEnd),
        debounceId: null,
        debounceMs: {{ $debounceMs }},
        select(value) {
            this.localType = value;
            this.open = false;
            if (value === 'custom-range') {
                this.customOpen = true;
                this.$nextTick(() => this.$refs.customStart?.focus());
            } else {
                this.customOpen = false;
            }
            this.commitType();
        },
        commitType() {
            clearTimeout(this.debounceId);
            this.debounceId = setTimeout(() => {
                $wire.set('periodType', this.localType);
            }, this.debounceMs);
        },
        commitRange() {
            clearTimeout(this.debounceId);
            this.debounceId = setTimeout(() => {
                $wire.set('customStart', this.localStart);
                $wire.set('customEnd', this.localEnd);
            }, this.debounceMs);
        },
        toggleListbox() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => this.$refs.currentOption?.focus());
            }
        },
        focusNext() {
            const options = Array.from(this.$refs.listbox?.querySelectorAll('[role=option]') ?? []);
            const current = options.indexOf(document.activeElement);
            (options[current + 1] ?? options[0])?.focus();
        },
        focusPrev() {
            const options = Array.from(this.$refs.listbox?.querySelectorAll('[role=option]') ?? []);
            const current = options.indexOf(document.activeElement);
            (options[current - 1] ?? options[options.length - 1])?.focus();
        },
    }"
    @keydown.escape.window="open = false; customOpen = (localType === 'custom-range')"
>
    <button
        type="button"
        class="gp-period-button"
        aria-haspopup="listbox"
        :aria-expanded="open.toString()"
        aria-controls="{{ $listboxId }}"
        aria-label="Sélectionner une période"
        @click="toggleListbox()"
        @keydown.down.prevent="open = true; $nextTick(() => $refs.currentOption?.focus())"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="16" y1="2" x2="16" y2="6"></line>
            <line x1="8" y1="2" x2="8" y2="6"></line>
            <line x1="3" y1="10" x2="21" y2="10"></line>
        </svg>
        <span class="gp-period-label" x-text="(() => {
            const labels = @js(collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->label()])->toArray());
            return labels[localType] ?? '{{ $this->currentLabel }}';
        })()">{{ $this->currentLabel }}</span>
        <svg class="gp-period-chevron" :class="open ? 'is-open' : ''" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <polyline points="6 9 12 15 18 9"></polyline>
        </svg>
    </button>

    <ul
        id="{{ $listboxId }}"
        x-ref="listbox"
        role="listbox"
        aria-label="Périodes disponibles"
        class="gp-period-listbox"
        x-show="open"
        x-transition.opacity.duration.150ms
        x-trap="open"
        @keydown.down.prevent="focusNext()"
        @keydown.up.prevent="focusPrev()"
        @click.outside="open = false"
        style="display: none;"
    >
        @foreach ($types as $type)
            <li
                role="option"
                tabindex="{{ $type->value === $currentValue ? '0' : '-1' }}"
                :aria-selected="(localType === @js($type->value)).toString()"
                x-ref="{{ $type->value === $currentValue ? 'currentOption' : '' }}"
                @click="select(@js($type->value))"
                @keydown.enter.prevent="select(@js($type->value))"
                @keydown.space.prevent="select(@js($type->value))"
                class="gp-period-option"
                :class="localType === @js($type->value) ? 'is-active' : ''"
            >
                <span>{{ $type->label() }}</span>
                <svg x-show="localType === @js($type->value)" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </li>
        @endforeach
    </ul>

    {{-- Custom range panel — separate a11y surface with its own focus trap --}}
    <div
        id="{{ $panelId }}"
        class="gp-period-custom"
        role="dialog"
        aria-label="Plage personnalisée"
        x-show="customOpen"
        x-transition.opacity.duration.150ms
        x-trap="customOpen"
        @keydown.escape.stop="customOpen = false"
        style="display: none;"
    >
        <div class="gp-period-custom-row">
            <label class="gp-period-custom-field">
                <span>Du</span>
                <input
                    x-ref="customStart"
                    type="date"
                    x-model="localStart"
                    @change="commitRange()"
                    aria-label="Date de début"
                >
            </label>
            <label class="gp-period-custom-field">
                <span>Au</span>
                <input
                    type="date"
                    x-model="localEnd"
                    @change="commitRange()"
                    aria-label="Date de fin"
                >
            </label>
        </div>
        <button
            type="button"
            class="gp-period-custom-close"
            @click="customOpen = false"
            aria-label="Fermer le sélecteur de plage personnalisée"
        >
            Fermer
        </button>
    </div>
</div>
