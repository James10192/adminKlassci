<x-filament-panels::page>

    {{-- Poll toutes les 3s quand un check global est en cours --}}
    @if ($isRunningAll)
        <div wire:poll.3000ms="pollCheck"></div>
    @endif

    {{-- Bannière "en cours" --}}
    @if ($isRunningAll)
        <div class="flex items-center gap-3 rounded-xl px-5 py-3 mb-6 text-sm font-medium" style="background-color:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;">
            <svg class="w-4 h-4 animate-spin flex-shrink-0" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
            </svg>
            Vérification de tous les tenants en cours — la page se mettra à jour automatiquement dès la fin...
        </div>
    @endif

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
            :disabled="$isRunningAll"
            color="primary"
            icon="heroicon-o-arrow-path"
            size="md"
        >
            {{ $isRunningAll ? 'Vérification en cours...' : 'Vérifier tous les tenants' }}
        </x-filament::button>
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">

        {{-- Healthy --}}
        <div class="rounded-2xl shadow-md px-6 py-5 flex items-center gap-4" style="background-color: #10b981; color: white;">
            <div class="flex-shrink-0 w-12 h-12 rounded-xl flex items-center justify-center" style="background-color: rgba(255,255,255,0.2);">
                <x-heroicon-o-check-circle class="w-6 h-6" style="color: white;" />
            </div>
            <div>
                <div class="text-3xl font-bold leading-none">{{ $stats['healthy'] }}</div>
                <div class="text-sm mt-1" style="color: #d1fae5;">Tenants sains</div>
            </div>
            <div class="ml-auto">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold" style="background-color: rgba(255,255,255,0.25); color: white;">
                    Sain
                </span>
            </div>
        </div>

        {{-- Degraded --}}
        <div class="rounded-2xl shadow-md px-6 py-5 flex items-center gap-4" style="background-color: #f59e0b; color: white;">
            <div class="flex-shrink-0 w-12 h-12 rounded-xl flex items-center justify-center" style="background-color: rgba(255,255,255,0.2);">
                <x-heroicon-o-exclamation-triangle class="w-6 h-6" style="color: white;" />
            </div>
            <div>
                <div class="text-3xl font-bold leading-none">{{ $stats['degraded'] }}</div>
                <div class="text-sm mt-1" style="color: #fef3c7;">Dégradés</div>
            </div>
            <div class="ml-auto">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold" style="background-color: rgba(255,255,255,0.25); color: white;">
                    Avertissement
                </span>
            </div>
        </div>

        {{-- Critical --}}
        <div class="rounded-2xl shadow-md px-6 py-5 flex items-center gap-4" style="background-color: #ef4444; color: white;">
            <div class="flex-shrink-0 w-12 h-12 rounded-xl flex items-center justify-center" style="background-color: rgba(255,255,255,0.2);">
                <x-heroicon-o-x-circle class="w-6 h-6" style="color: white;" />
            </div>
            <div>
                <div class="text-3xl font-bold leading-none">{{ $stats['unhealthy'] }}</div>
                <div class="text-sm mt-1" style="color: #fecaca;">Critiques</div>
            </div>
            <div class="ml-auto">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold" style="background-color: rgba(255,255,255,0.25); color: white;">
                    Critique
                </span>
            </div>
        </div>

    </div>

    {{-- Log Rotation Panel --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm px-6 py-5 mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex items-center gap-3 flex-1">
                <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center" style="background-color: #f1f5f9;">
                    <x-heroicon-o-trash class="w-5 h-5 text-gray-500 dark:text-gray-400" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Rotation des logs</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Tronque les fichiers laravel.log pour libérer de l'espace disque.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 sm:gap-4">
                {{-- Days selector --}}
                <div class="flex items-center gap-2">
                    <label class="text-xs font-medium text-gray-600 dark:text-gray-300 whitespace-nowrap">Conserver</label>
                    <select
                        wire:model="logRotateDays"
                        class="text-xs rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 px-2 py-1.5 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                    >
                        <option value="7">7 jours</option>
                        <option value="14">14 jours</option>
                        <option value="30">30 jours</option>
                        <option value="60">60 jours</option>
                        <option value="90">90 jours</option>
                    </select>
                </div>

                {{-- Dry-run toggle --}}
                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        wire:model="logRotateDryRun"
                        class="rounded border-gray-300 dark:border-gray-600 text-primary-600"
                    />
                    <span class="text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap">Mode simulation</span>
                </label>

                {{-- Action button --}}
                <x-filament::button
                    wire:click="rotateLogs"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-not-allowed"
                    color="gray"
                    icon="heroicon-o-arrow-path"
                    size="sm"
                >
                    <span wire:loading.remove wire:target="rotateLogs">
                        {{ $logRotateDryRun ? 'Simuler la rotation' : 'Lancer la rotation' }}
                    </span>
                    <span wire:loading wire:target="rotateLogs">Rotation en cours...</span>
                </x-filament::button>
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

                $borderStyle = match($globalStatus) {
                    'unhealthy' => 'border-left: 4px solid #ef4444;',
                    'degraded'  => 'border-left: 4px solid #f59e0b;',
                    default     => 'border-left: 4px solid #10b981;',
                };
                $headerBgStyle = match($globalStatus) {
                    'unhealthy' => 'background-color:#fef2f2;',
                    'degraded'  => 'background-color:#fffbeb;',
                    default     => 'background-color:#f0fdf4;',
                };
                $statusLabel = match($globalStatus) {
                    'unhealthy' => 'Critique',
                    'degraded'  => 'Dégradé',
                    default     => 'Sain',
                };
                $statusBadgeStyle = match($globalStatus) {
                    'unhealthy' => 'background-color:#ef4444;color:white;',
                    'degraded'  => 'background-color:#f59e0b;color:white;',
                    default     => 'background-color:#10b981;color:white;',
                };
                $statusIconComp = match($globalStatus) {
                    'unhealthy' => 'heroicon-o-x-circle',
                    'degraded'  => 'heroicon-o-exclamation-triangle',
                    default     => 'heroicon-o-check-circle',
                };
                $statusIconStyle = match($globalStatus) {
                    'unhealthy' => 'color:#ef4444;',
                    'degraded'  => 'color:#f59e0b;',
                    default     => 'color:#10b981;',
                };
            @endphp

            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm overflow-hidden" style="{{ $borderStyle }}">

                {{-- Tenant header --}}
                <div class="px-6 py-4 flex items-center justify-between border-b border-gray-100 dark:border-gray-700" style="{{ $headerBgStyle }}">
                    <div class="flex items-center gap-3">
                        <x-dynamic-component :component="$statusIconComp" class="w-5 h-5 flex-shrink-0" style="{{ $statusIconStyle }}" />
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
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold" style="{{ $statusBadgeStyle }}">
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

                            // Styles inline pour garantir les couleurs (évite les conflits CSS Filament)
                            $cardStyle = match($statusValue) {
                                'healthy'   => 'background-color:#f0fdf4;border-color:#86efac;',
                                'degraded'  => 'background-color:#fffbeb;border-color:#fcd34d;',
                                'unhealthy' => 'background-color:#fef2f2;border-color:#fca5a5;',
                                default     => 'background-color:#f9fafb;border-color:#e5e7eb;',
                            };
                            $iconStyle = match($statusValue) {
                                'healthy'   => 'color:#16a34a;',
                                'degraded'  => 'color:#d97706;',
                                'unhealthy' => 'color:#dc2626;',
                                default     => 'color:#9ca3af;',
                            };
                            $dotStyle = match($statusValue) {
                                'healthy'   => 'background-color:#22c55e;',
                                'degraded'  => 'background-color:#f59e0b;',
                                'unhealthy' => 'background-color:#ef4444;',
                                default     => 'background-color:#9ca3af;',
                            };
                            $labelStyle = match($statusValue) {
                                'healthy'   => 'color:#15803d;',
                                'degraded'  => 'color:#b45309;',
                                'unhealthy' => 'color:#b91c1c;',
                                default     => 'color:#6b7280;',
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
                            class="rounded-xl border px-3 py-3 flex flex-col items-center gap-1.5 text-center"
                            style="{{ $cardStyle }}"
                            title="{{ $check['details'] ?? '' }}"
                        >
                            <x-dynamic-component :component="$checkIcon" class="w-5 h-5" style="{{ $iconStyle }}" />

                            <div class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $checkLabel }}</div>

                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="{{ $dotStyle }}"></span>
                                <span class="text-xs font-bold" style="{{ $labelStyle }}">{{ $statusDisplay }}</span>
                            </div>

                            @if (!empty($check['response_time_ms']))
                                <div class="text-xs text-gray-400 dark:text-gray-500">{{ $check['response_time_ms'] }} ms</div>
                            @endif

                            @if (!empty($check['details']) && $statusValue !== 'healthy')
                                <div class="text-xs leading-tight line-clamp-2 opacity-80" style="{{ $labelStyle }}" title="{{ $check['details'] }}">
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
