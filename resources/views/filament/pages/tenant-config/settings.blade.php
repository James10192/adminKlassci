<x-filament-panels::page>

    @include('filament.pages.tenant-config._tenant-selector')

    @if ($selectedTenantId && !empty($formValues))
        @foreach ($this->getSettingGroups() as $groupKey => $group)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                    <x-dynamic-component :component="$group['icon']" class="w-5 h-5 text-primary-500" />
                    {{ $group['label'] }}
                </h3>

                @php
                    $hasColors = collect($group['keys'])->contains(fn ($meta) => $meta['type'] === 'color');
                    $hasBooleans = collect($group['keys'])->contains(fn ($meta) => $meta['type'] === 'boolean');
                    $hasNumbers = collect($group['keys'])->contains(fn ($meta) => $meta['type'] === 'number');
                @endphp

                {{-- Color inputs --}}
                @if ($hasColors)
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                        @foreach ($group['keys'] as $key => $meta)
                            @if ($meta['type'] === 'color')
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">{{ $meta['label'] }}</label>
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="color"
                                            wire:model="formValues.{{ $key }}"
                                            class="h-10 w-14 rounded-lg border border-gray-300 dark:border-gray-600 cursor-pointer"
                                        />
                                        <input
                                            type="text"
                                            wire:model="formValues.{{ $key }}"
                                            class="block w-full rounded-lg border-gray-300 shadow-sm text-xs font-mono focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                            maxlength="7"
                                        />
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- Boolean toggles --}}
                @if ($hasBooleans)
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                        @foreach ($group['keys'] as $key => $meta)
                            @if ($meta['type'] === 'boolean')
                                <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer transition-colors">
                                    <input
                                        type="checkbox"
                                        wire:model="formValues.{{ $key }}"
                                        value="1"
                                        @checked(($formValues[$key] ?? '0') === '1' || ($formValues[$key] ?? '0') === 1 || ($formValues[$key] ?? false) === true)
                                        class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                                    />
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ $meta['label'] }}</span>
                                </label>
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- Number inputs --}}
                @if ($hasNumbers)
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        @foreach ($group['keys'] as $key => $meta)
                            @if ($meta['type'] === 'number')
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $meta['label'] }}</label>
                                    <input
                                        type="number"
                                        wire:model="formValues.{{ $key }}"
                                        min="0"
                                        max="10"
                                        step="1"
                                        class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"
                                    />
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        {{-- Bouton sauvegarder --}}
        <div class="flex justify-end">
            <button
                wire:click="saveSettings"
                wire:loading.attr="disabled"
                class="fi-btn inline-flex items-center gap-1.5 rounded-lg px-5 py-2.5 text-sm font-semibold text-white bg-primary-600 hover:bg-primary-500 shadow-sm transition-colors disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="saveSettings">
                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Enregistrer tous les paramètres
                </span>
                <span wire:loading wire:target="saveSettings">Enregistrement...</span>
            </button>
        </div>

    @elseif ($selectedTenantId && empty($formValues))
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-8 text-center">
            <svg class="mx-auto h-8 w-8 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <p class="mt-3 text-sm text-gray-500">Chargement des paramètres...</p>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <h3 class="mt-4 text-sm font-medium text-gray-900 dark:text-white">Aucun tenant sélectionné</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Sélectionnez un établissement pour voir ses paramètres PDF et bulletin.</p>
        </div>
    @endif

</x-filament-panels::page>
