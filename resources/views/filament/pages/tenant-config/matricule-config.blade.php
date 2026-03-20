<x-filament-panels::page>

    @include('filament.pages.tenant-config._tenant-selector')

    @if ($selectedTenantId)
        {{-- Tableau des configs existantes --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    Configurations matricule ({{ count($configs) }})
                </h3>
                <button
                    wire:click="openForm"
                    class="fi-btn fi-btn-size-sm inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-white bg-primary-600 hover:bg-primary-500 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Ajouter
                </button>
            </div>

            @if (count($configs) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase bg-gray-50 dark:bg-gray-800 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3 rounded-tl-lg">Niveau</th>
                                <th class="px-4 py-3">Nom</th>
                                <th class="px-4 py-3">Préfixe</th>
                                <th class="px-4 py-3">Format année</th>
                                <th class="px-4 py-3">Digits</th>
                                <th class="px-4 py-3">Actif</th>
                                <th class="px-4 py-3 rounded-tr-lg text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($configs as $config)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <td class="px-4 py-3 font-mono font-semibold text-gray-900 dark:text-white">{{ $config['niveau_etude_code'] }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $config['niveau_etude_name'] }}</td>
                                    <td class="px-4 py-3 font-mono text-gray-500">{{ $config['prefixe'] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $config['annee_format'] }} chiffres</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $config['numero_digits'] }}</td>
                                    <td class="px-4 py-3">
                                        @if ($config['is_active'] ?? false)
                                            <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">Actif</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-400">Inactif</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button wire:click="openForm({{ $config['id'] }})" class="text-primary-600 hover:text-primary-500" title="Modifier">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                </svg>
                                            </button>
                                            <button wire:click="deleteConfig({{ $config['id'] }})" wire:confirm="Supprimer cette configuration ?" class="text-red-500 hover:text-red-400" title="Supprimer">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-8">
                    Aucune configuration matricule pour ce tenant.
                </p>
            @endif
        </div>

        {{-- Formulaire create/edit --}}
        @if ($showForm)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                    {{ $editingId ? 'Modifier la configuration' : 'Nouvelle configuration' }}
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    {{-- Niveau code --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Code niveau *</label>
                        <input type="text" wire:model="formNiveauCode" placeholder="ex: BTS, LICENCE"
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm" />
                        @error('formNiveauCode') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Niveau name --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom du niveau *</label>
                        <input type="text" wire:model="formNiveauName" placeholder="ex: Brevet de Technicien Supérieur"
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm" />
                        @error('formNiveauName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    {{-- Préfixe --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Préfixe (optionnel)</label>
                        <input type="text" wire:model="formPrefixe" placeholder="ex: L, M"
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm" />
                    </div>

                    {{-- Année format --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Format année *</label>
                        <select wire:model="formAnneeFormat"
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                            <option value="2">2 chiffres (ex: 26)</option>
                            <option value="4">4 chiffres (ex: 2026)</option>
                        </select>
                    </div>

                    {{-- Numéro digits --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nombre de digits *</label>
                        <select wire:model="formNumeroDigits"
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                            @for ($i = 3; $i <= 6; $i++)
                                <option value="{{ $i }}">{{ $i }} chiffres (ex: {{ str_pad('1', $i, '0', STR_PAD_LEFT) }})</option>
                            @endfor
                        </select>
                    </div>

                    {{-- Description --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                        <input type="text" wire:model="formDescription" placeholder="Description optionnelle"
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm" />
                    </div>
                </div>

                {{-- Prévisualisation --}}
                <div class="mb-6 p-4 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Aperçu matricule</span>
                    <p class="mt-1 text-lg font-mono font-bold text-primary-600 dark:text-primary-400">
                        {{ $this->generatePreview() }}
                    </p>
                </div>

                {{-- Boutons --}}
                <div class="flex justify-end gap-3">
                    <button
                        wire:click="resetForm"
                        class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700"
                    >
                        Annuler
                    </button>
                    <button
                        wire:click="saveConfig"
                        wire:loading.attr="disabled"
                        class="fi-btn inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-primary-600 hover:bg-primary-500 disabled:opacity-50"
                    >
                        {{ $editingId ? 'Mettre à jour' : 'Créer' }}
                    </button>
                </div>
            </div>
        @endif

    @else
        {{-- État vide --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
            </svg>
            <h3 class="mt-4 text-sm font-medium text-gray-900 dark:text-white">Aucun tenant sélectionné</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Sélectionnez un établissement pour configurer les matricules.</p>
        </div>
    @endif

</x-filament-panels::page>
