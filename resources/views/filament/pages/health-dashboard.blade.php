<x-filament-panels::page>

    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-8">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Dernière vérification :
            <span class="font-semibold text-gray-700 dark:text-gray-300">
                {{ $lastCheckedAt ? $lastCheckedAt->diffForHumans() : 'Jamais effectuée' }}
            </span>
        </p>
        <x-filament::button
            wire:click="runAllChecks"
            wire:loading.attr="disabled"
            wire:loading.class="opacity-60 cursor-not-allowed"
            color="primary"
            icon="heroicon-o-arrow-path"
            size="md"
        >
            <span wire:loading.remove wire:target="runAllChecks">Vérifier tous les tenants</span>
            <span wire:loading wire:target="runAllChecks">Vérification en cours...</span>
        </x-filament::button>
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">

        {{-- Healthy --}}
        <div class="rounded-2xl bg-emerald-500 shadow-md px-6 py-5 flex items-center gap-4 text-white">
            <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center">
                <x-heroicon-o-check-circle class="w-6 h-6 text-white" />
            </div>
            <div>
                <div class="text-3xl font-bold leading-none">{{ $stats['healthy'] }}</div>
                <div class="text-sm text-emerald-100 mt-1">Tenants sains</div>
            </div>
            <div class="ml-auto">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-white/25 text-white">
                    Sain
                </span>
            </div>
        </div>

        {{-- Degraded --}}
        <div class="rounded-2xl bg-amber-400 shadow-md px-6 py-5 flex items-center gap-4 text-white">
            <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center">
                <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-white" />
            </div>
            <div>
                <div class="text-3xl font-bold leading-none">{{ $stats['degraded'] }}</div>
                <div class="text-sm text-amber-100 mt-1">Dégradés</div>
            </div>
            <div class="ml-auto">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-white/25 text-white">
                    Avertissement
                </span>
            </div>
        </div>

        {{-- Critical --}}
        <div class="rounded-2xl bg-red-500 shadow-md px-6 py-5 flex items-center gap-4 text-white">
            <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center">
                <x-heroicon-o-x-circle class="w-6 h-6 text-white" />
            </div>
            <div>
                <div class="text-3xl font-bold leading-none">{{ $stats['unhealthy'] }}</div>
                <div class="text-sm text-red-100 mt-1">Critiques</div>
            </div>
            <div class="ml-auto">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-white/25 text-white">
                    Critique
                </span>
            </div>
        </div>

    </div>

    {{-- Tenant list --}}
    <div class="space-y-5">
        @forelse ($tenantChecks as $data)
            @php
                $tenant       = $data['tenant'];
                $checks       = $data['checks'];
                $globalStatus = $data['global_status'];

                $borderColor = match($globalStatus) {
                    'unhealthy' => 'border-l-red-500',
                    'degraded'  => 'border-l-amber-400',
                    default     => 'border-l-emerald-500',
                };
                $headerBg = match($globalStatus) {
                    'unhealthy' => 'bg-red-50 dark:bg-red-950/30',
                    'degraded'  => 'bg-amber-50 dark:bg-amber-950/30',
                    default     => 'bg-emerald-50 dark:bg-emerald-950/30',
                };
                $statusLabel = match($globalStatus) {
                    'unhealthy' => 'Critique',
                    'degraded'  => 'Dégradé',
                    default     => 'Sain',
                };
                $statusBadge = match($globalStatus) {
                    'unhealthy' => 'bg-red-500 text-white',
                    'degraded'  => 'bg-amber-400 text-white',
                    default     => 'bg-emerald-500 text-white',
                };
                $statusIconComp = match($globalStatus) {
                    'unhealthy' => 'heroicon-o-x-circle',
                    'degraded'  => 'heroicon-o-exclamation-triangle',
                    default     => 'heroicon-o-check-circle',
                };
                $statusIconColor = match($globalStatus) {
                    'unhealthy' => 'text-red-500',
                    'degraded'  => 'text-amber-500',
                    default     => 'text-emerald-500',
                };
            @endphp

            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 border-l-4 {{ $borderColor }} shadow-sm overflow-hidden">

                {{-- Tenant header --}}
                <div class="{{ $headerBg }} px-6 py-4 flex items-center justify-between border-b border-gray-100 dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <x-dynamic-component :component="$statusIconComp" class="w-5 h-5 flex-shrink-0 {{ $statusIconColor }}" />
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white text-base leading-tight">
                                {{ $tenant->name }}
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                {{ $tenant->subdomain }}.klassci.com
                                <span class="mx-1 text-gray-300 dark:text-gray-600">&bull;</span>
                                {{ ucfirst($tenant->plan) }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusBadge }}">
                            {{ $statusLabel }}
                        </span>
                        <span class="text-xs text-gray-400 dark:text-gray-500">
                            @if ($data['last_check'])
                                {{ $data['last_check']->diffForHumans() }}
                            @else
                                Jamais vérifié
                            @endif
                        </span>
                        <x-filament::button
                            wire:click="runTenantCheck('{{ $tenant->code }}')"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-60"
                            size="xs"
                            color="gray"
                            icon="heroicon-o-arrow-path"
                        >
                            Re-vérifier
                        </x-filament::button>
                    </div>
                </div>

                {{-- Check types grid --}}
                <div class="px-6 py-5 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                    @foreach ($checks as $checkType => $check)
                        @php
                            $checkIcon = match($checkType) {
                                'http_status'         => 'heroicon-o-globe-alt',
                                'database_connection' => 'heroicon-o-circle-stack',
                                'disk_space'          => 'heroicon-o-server',
                                'ssl_certificate'     => 'heroicon-o-lock-closed',
                                'application_errors'  => 'heroicon-o-bug-ant',
                                'queue_workers'       => 'heroicon-o-queue-list',
                                default               => 'heroicon-o-question-mark-circle',
                            };
                            $checkLabel = match($checkType) {
                                'http_status'         => 'HTTP',
                                'database_connection' => 'Base de données',
                                'disk_space'          => 'Disque',
                                'ssl_certificate'     => 'SSL',
                                'application_errors'  => 'Erreurs app',
                                'queue_workers'       => 'Files d\'attente',
                                default               => $checkType,
                            };
                            $statusValue = $check['status'] ?? 'unknown';

                            // Fonds colorés bien visibles
                            $cardBg = match($statusValue) {
                                'healthy'   => 'bg-emerald-50 dark:bg-emerald-900/25 border-emerald-300 dark:border-emerald-700',
                                'degraded'  => 'bg-amber-50 dark:bg-amber-900/25 border-amber-300 dark:border-amber-700',
                                'unhealthy' => 'bg-red-50 dark:bg-red-900/25 border-red-300 dark:border-red-700',
                                default     => 'bg-gray-50 dark:bg-gray-700/30 border-gray-200 dark:border-gray-700',
                            };
                            $dotColor = match($statusValue) {
                                'healthy'   => 'bg-emerald-500',
                                'degraded'  => 'bg-amber-500',
                                'unhealthy' => 'bg-red-500',
                                default     => 'bg-gray-400',
                            };
                            $iconColor = match($statusValue) {
                                'healthy'   => 'text-emerald-600 dark:text-emerald-400',
                                'degraded'  => 'text-amber-600 dark:text-amber-400',
                                'unhealthy' => 'text-red-600 dark:text-red-400',
                                default     => 'text-gray-400 dark:text-gray-500',
                            };
                            $labelColor = match($statusValue) {
                                'healthy'   => 'text-emerald-700 dark:text-emerald-300',
                                'degraded'  => 'text-amber-700 dark:text-amber-300',
                                'unhealthy' => 'text-red-700 dark:text-red-300',
                                default     => 'text-gray-500 dark:text-gray-400',
                            };
                            $statusDisplay = match($statusValue) {
                                'healthy'   => 'Sain',
                                'degraded'  => 'Dégradé',
                                'unhealthy' => 'Critique',
                                'unknown'   => 'Inconnu',
                                default     => ucfirst($statusValue),
                            };
                        @endphp

                        <div
                            class="rounded-xl border {{ $cardBg }} px-3 py-3 flex flex-col items-center gap-1.5 text-center"
                            title="{{ $check['details'] ?? '' }}"
                        >
                            <x-dynamic-component :component="$checkIcon" class="w-5 h-5 {{ $iconColor }}" />

                            <div class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $checkLabel }}</div>

                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full flex-shrink-0 {{ $dotColor }}"></span>
                                <span class="text-xs font-bold {{ $labelColor }}">{{ $statusDisplay }}</span>
                            </div>

                            @if (!empty($check['response_time_ms']))
                                <div class="text-xs text-gray-400 dark:text-gray-500">{{ $check['response_time_ms'] }} ms</div>
                            @endif

                            @if (!empty($check['details']) && $statusValue !== 'healthy')
                                <div class="text-xs {{ $labelColor }} leading-tight line-clamp-2 opacity-80" title="{{ $check['details'] }}">
                                    {{ Str::limit($check['details'], 32) }}
                                </div>
                            @endif
                        </div>

                    @endforeach
                </div>

            </div>
        @empty
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm px-8 py-16 text-center">
                <x-heroicon-o-heart class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600 mb-4" />
                <p class="text-base font-semibold text-gray-700 dark:text-gray-300">Aucun tenant à surveiller</p>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Lancez une vérification pour voir l'état des tenants.</p>
            </div>
        @endforelse
    </div>

    {{-- Link to full issues list --}}
    @if ($stats['degraded'] + $stats['unhealthy'] > 0)
        <div class="mt-6 text-center">
            <a href="{{ route('filament.admin.resources.tenant-health-checks.index') }}"
               class="text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 transition-colors">
                Voir tous les problèmes en détail
                <x-heroicon-m-arrow-right class="w-3.5 h-3.5 inline ml-1 -mt-0.5" />
            </a>
        </div>
    @endif

</x-filament-panels::page>
