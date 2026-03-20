<x-filament-panels::page>

    @include('filament.pages.tenant-config._tenant-selector')

    {{-- Contenu config --}}
    @if ($selectedTenantId)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">
                Choix du style de bulletin PDF
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                {{-- Card Yakro --}}
                <label
                    class="relative cursor-pointer rounded-xl border-2 p-6 transition-all duration-200 hover:shadow-md
                        {{ $bulletinStyle === 'yakro' ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/20 ring-2 ring-primary-500/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300' }}"
                >
                    <input type="radio" wire:model.live="bulletinStyle" value="yakro" class="sr-only" />
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 mt-1">
                            <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center
                                {{ $bulletinStyle === 'yakro' ? 'border-primary-500 bg-primary-500' : 'border-gray-300 dark:border-gray-600' }}">
                                @if ($bulletinStyle === 'yakro')
                                    <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="text-base font-semibold text-gray-900 dark:text-white">Style Yakro</div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Template classique avec en-tête institutionnel, colonnes matières alignées, cadre signature en bas de page.
                            </p>
                        </div>
                    </div>
                </label>

                {{-- Card Abidjan --}}
                <label
                    class="relative cursor-pointer rounded-xl border-2 p-6 transition-all duration-200 hover:shadow-md
                        {{ $bulletinStyle === 'abidjan' ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/20 ring-2 ring-primary-500/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300' }}"
                >
                    <input type="radio" wire:model.live="bulletinStyle" value="abidjan" class="sr-only" />
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 mt-1">
                            <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center
                                {{ $bulletinStyle === 'abidjan' ? 'border-primary-500 bg-primary-500' : 'border-gray-300 dark:border-gray-600' }}">
                                @if ($bulletinStyle === 'abidjan')
                                    <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="text-base font-semibold text-gray-900 dark:text-white">Style Abidjan</div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Template moderne avec logo centré, tableau de notes détaillé, statistiques de classe intégrées.
                            </p>
                        </div>
                    </div>
                </label>
            </div>

            {{-- Bouton sauvegarder --}}
            <div class="flex justify-end">
                <button
                    wire:click="saveConfig"
                    wire:loading.attr="disabled"
                    class="fi-btn fi-btn-size-md relative inline-flex items-center justify-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors duration-75 bg-primary-600 hover:bg-primary-500 focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="saveConfig">
                        <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Enregistrer
                    </span>
                    <span wire:loading wire:target="saveConfig">
                        <svg class="w-4 h-4 mr-1 inline animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        Enregistrement...
                    </span>
                </button>
            </div>
        </div>
    @else
        {{-- État vide --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            <h3 class="mt-4 text-sm font-medium text-gray-900 dark:text-white">Aucun tenant sélectionné</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Sélectionnez un établissement pour configurer le style de bulletin.</p>
        </div>
    @endif

</x-filament-panels::page>
