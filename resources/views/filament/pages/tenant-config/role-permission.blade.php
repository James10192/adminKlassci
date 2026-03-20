<x-filament-panels::page>

    {{-- Sélecteur de tenant --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 mb-6">
        <div class="flex items-center gap-4">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
                Établissement :
            </label>
            <select
                wire:model.live="selectedTenantId"
                class="fi-select-input block w-full max-w-md rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
            >
                <option value="">— Sélectionner un tenant —</option>
                @foreach ($tenants as $t)
                    <option value="{{ $t['id'] }}">{{ $t['name'] }} ({{ $t['code'] }})</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($selectedTenantId && count($roles) > 0)
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

            {{-- Liste des rôles (sidebar gauche) --}}
            <div class="lg:col-span-1">
                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                    <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">Rôles</h3>
                    <nav class="space-y-1">
                        @foreach ($roles as $role)
                            <button
                                wire:click="selectRole({{ $role['id'] }})"
                                class="w-full flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium transition-colors
                                    {{ $selectedRoleId === $role['id']
                                        ? 'bg-primary-50 text-primary-700 ring-1 ring-primary-200 dark:bg-primary-950/30 dark:text-primary-400 dark:ring-primary-800'
                                        : 'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800' }}"
                            >
                                <span class="flex items-center gap-2">
                                    <svg class="w-4 h-4 {{ $selectedRoleId === $role['id'] ? 'text-primary-500' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    {{ ucfirst($role['name']) }}
                                </span>
                                @if ($selectedRoleId === $role['id'])
                                    <span class="inline-flex items-center rounded-full bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/50 dark:text-primary-300">
                                        {{ count($rolePermissionIds) }}
                                    </span>
                                @endif
                            </button>
                        @endforeach
                    </nav>
                </div>
            </div>

            {{-- Grille des permissions (contenu principal) --}}
            <div class="lg:col-span-3">
                @if ($selectedRoleId)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Permissions de
                                <span class="text-primary-600 dark:text-primary-400">{{ ucfirst($selectedRoleName) }}</span>
                            </h3>
                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                {{ count($rolePermissionIds) }} / {{ count($permissions) }} permissions
                            </span>
                        </div>

                        @foreach ($groupedPermissions as $group => $perms)
                            <div class="mb-6 last:mb-0">
                                <h4 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3 pb-2 border-b border-gray-100 dark:border-gray-800">
                                    {{ $group }} ({{ count($perms) }})
                                </h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                                    @foreach ($perms as $perm)
                                        <label
                                            class="flex items-center gap-2.5 px-3 py-2 rounded-lg cursor-pointer transition-colors
                                                {{ in_array($perm['id'], $rolePermissionIds)
                                                    ? 'bg-primary-50/50 dark:bg-primary-950/10'
                                                    : 'hover:bg-gray-50 dark:hover:bg-gray-800/50' }}"
                                        >
                                            <input
                                                type="checkbox"
                                                wire:click="togglePermission({{ $perm['id'] }})"
                                                @checked(in_array($perm['id'], $rolePermissionIds))
                                                class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                                            />
                                            <span class="text-sm text-gray-700 dark:text-gray-300 truncate" title="{{ $perm['name'] }}">
                                                {{ $perm['name'] }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        {{-- Bouton sauvegarder --}}
                        <div class="flex justify-end mt-6 pt-4 border-t border-gray-100 dark:border-gray-800">
                            <button
                                wire:click="savePermissions"
                                wire:loading.attr="disabled"
                                class="fi-btn inline-flex items-center gap-1.5 rounded-lg px-5 py-2.5 text-sm font-semibold text-white bg-primary-600 hover:bg-primary-500 shadow-sm transition-colors disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="savePermissions">
                                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                    Enregistrer les permissions
                                </span>
                                <span wire:loading wire:target="savePermissions">Enregistrement...</span>
                            </button>
                        </div>
                    </div>
                @else
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-12 text-center">
                        <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19l-7-7 7-7"/>
                        </svg>
                        <h3 class="mt-3 text-sm font-medium text-gray-900 dark:text-white">Sélectionnez un rôle</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Cliquez sur un rôle à gauche pour voir et modifier ses permissions.</p>
                    </div>
                @endif
            </div>
        </div>

    @elseif ($selectedTenantId && count($roles) === 0)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-8 text-center">
            <p class="text-sm text-gray-500">Chargement des rôles...</p>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <h3 class="mt-4 text-sm font-medium text-gray-900 dark:text-white">Aucun tenant sélectionné</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Sélectionnez un établissement pour gérer les rôles et permissions.</p>
        </div>
    @endif

</x-filament-panels::page>
