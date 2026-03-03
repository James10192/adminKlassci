<x-filament-panels::page>

    {{-- Header actions --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Dernière vérification :
                <span class="font-medium text-gray-700 dark:text-gray-300">
                    {{ $lastCheckedAt ? $lastCheckedAt->diffForHumans() : 'Jamais' }}
                </span>
            </p>
        </div>
        <div class="flex gap-3">
            <x-filament::button
                wire:click="runAllChecks"
                wire:loading.attr="disabled"
                color="primary"
                icon="heroicon-o-arrow-path"
                wire:loading.class="opacity-50"
            >
                <span wire:loading.remove wire:target="runAllChecks">Vérifier tous les tenants</span>
                <span wire:loading wire:target="runAllChecks">Vérification en cours...</span>
            </x-filament::button>
        </div>
    </div>

    {{-- Summary bar --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                <x-heroicon-o-check-circle class="w-5 h-5 text-green-600 dark:text-green-400" />
            </div>
            <div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['healthy'] }}</div>
                <div class="text-xs text-gray-500">Tenants sains</div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-yellow-600 dark:text-yellow-400" />
            </div>
            <div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['degraded'] }}</div>
                <div class="text-xs text-gray-500">Dégradés</div>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                <x-heroicon-o-x-circle class="w-5 h-5 text-red-600 dark:text-red-400" />
            </div>
            <div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['unhealthy'] }}</div>
                <div class="text-xs text-gray-500">Critiques</div>
            </div>
        </div>
    </div>

    {{-- Tenant cards --}}
    <div class="grid grid-cols-1 gap-4">
        @forelse ($tenantChecks as $data)
            @php
                $tenant = $data['tenant'];
                $checks = $data['checks'];
                $globalStatus = $data['global_status'];
                $borderColor = match($globalStatus) {
                    'unhealthy' => 'border-red-500',
                    'degraded'  => 'border-yellow-500',
                    default     => 'border-green-500',
                };
                $headerBg = match($globalStatus) {
                    'unhealthy' => 'bg-red-50 dark:bg-red-900/10',
                    'degraded'  => 'bg-yellow-50 dark:bg-yellow-900/10',
                    default     => 'bg-green-50 dark:bg-green-900/10',
                };
                $statusIcon = match($globalStatus) {
                    'unhealthy' => '🔴',
                    'degraded'  => '🟡',
                    default     => '🟢',
                };
            @endphp

            <div class="bg-white dark:bg-gray-800 rounded-xl border-l-4 {{ $borderColor }} shadow-sm overflow-hidden">

                {{-- Tenant header --}}
                <div class="{{ $headerBg }} px-5 py-3 flex items-center justify-between border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <span class="text-lg">{{ $statusIcon }}</span>
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">
                                {{ $tenant->name }}
                            </h3>
                            <p class="text-xs text-gray-500">{{ $tenant->subdomain }}.klassci.com &bull; {{ ucfirst($tenant->plan) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-400">
                            @if ($data['last_check'])
                                {{ $data['last_check']->diffForHumans() }}
                            @else
                                Jamais vérifié
                            @endif
                        </span>
                        <x-filament::button
                            wire:click="runTenantCheck('{{ $tenant->code }}')"
                            wire:loading.attr="disabled"
                            size="xs"
                            color="gray"
                            icon="heroicon-o-arrow-path"
                        >
                            Re-vérifier
                        </x-filament::button>
                    </div>
                </div>

                {{-- Check types grid --}}
                <div class="px-5 py-4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                    @foreach ($checks as $checkType => $check)
                        @php
                            $checkIcon = match($checkType) {
                                'http_status'          => '🌐',
                                'database_connection'  => '🗄️',
                                'disk_space'           => '💾',
                                'ssl_certificate'      => '🔒',
                                'application_errors'   => '⚠️',
                                'queue_workers'        => '⚙️',
                                default                => '❓',
                            };
                            $checkLabel = match($checkType) {
                                'http_status'          => 'HTTP',
                                'database_connection'  => 'Database',
                                'disk_space'           => 'Disque',
                                'ssl_certificate'      => 'SSL',
                                'application_errors'   => 'App Errors',
                                'queue_workers'        => 'Queues',
                                default                => $checkType,
                            };
                            $statusBg = match($check['status'] ?? 'unknown') {
                                'healthy'   => 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800',
                                'degraded'  => 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800',
                                'unhealthy' => 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800',
                                default     => 'bg-gray-50 dark:bg-gray-700/30 border-gray-200 dark:border-gray-700',
                            };
                            $statusText = match($check['status'] ?? 'unknown') {
                                'healthy'   => 'text-green-700 dark:text-green-400',
                                'degraded'  => 'text-yellow-700 dark:text-yellow-400',
                                'unhealthy' => 'text-red-700 dark:text-red-400',
                                default     => 'text-gray-500 dark:text-gray-400',
                            };
                            $statusDot = match($check['status'] ?? 'unknown') {
                                'healthy'   => 'bg-green-500',
                                'degraded'  => 'bg-yellow-500',
                                'unhealthy' => 'bg-red-500',
                                default     => 'bg-gray-400',
                            };
                        @endphp

                        <div class="rounded-lg border {{ $statusBg }} p-3 text-center" title="{{ $check['details'] ?? '' }}">
                            <div class="text-xl mb-1">{{ $checkIcon }}</div>
                            <div class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $checkLabel }}</div>
                            <div class="flex items-center justify-center gap-1">
                                <span class="w-2 h-2 rounded-full {{ $statusDot }}"></span>
                                <span class="text-xs font-semibold {{ $statusText }}">
                                    {{ ucfirst($check['status'] ?? '—') }}
                                </span>
                            </div>
                            @if (!empty($check['response_time_ms']))
                                <div class="text-xs text-gray-400 mt-1">{{ $check['response_time_ms'] }} ms</div>
                            @endif
                            @if (!empty($check['details']) && ($check['status'] ?? '') !== 'healthy')
                                <div class="text-xs text-red-600 dark:text-red-400 mt-1 truncate" title="{{ $check['details'] }}">
                                    {{ Str::limit($check['details'], 30) }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

            </div>
        @empty
            <div class="text-center py-12 text-gray-500">
                <x-heroicon-o-heart class="w-12 h-12 mx-auto mb-3 text-gray-300" />
                <p class="font-medium">Aucun tenant à surveiller</p>
                <p class="text-sm">Lancez une vérification pour voir l'état des tenants.</p>
            </div>
        @endforelse
    </div>

    {{-- Link to full issues table --}}
    @if ($stats['degraded'] + $stats['unhealthy'] > 0)
        <div class="mt-4 text-center">
            <a href="{{ route('filament.admin.resources.tenant-health-checks.index') }}"
               class="text-sm text-primary-600 hover:text-primary-700 font-medium">
                Voir tous les problèmes détaillés →
            </a>
        </div>
    @endif

</x-filament-panels::page>
