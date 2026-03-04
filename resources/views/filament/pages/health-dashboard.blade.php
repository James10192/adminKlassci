<x-filament-panels::page>

    {{-- ═══════════════════════════════════════════════════════════════════
         MODAL TERMINAL — Vérification en temps réel
         Approche : background job + polling Livewire (compatible Varnish/proxy)
    ═══════════════════════════════════════════════════════════════════ --}}
    @if ($terminalOpen)
    <div
        x-data="healthTerminal()"
        x-init="init()"
        @terminal-update.window="onUpdate($event.detail)"
        style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.75);backdrop-filter:blur(4px);"
    >
        {{-- Poll Livewire toutes les 600ms tant que le terminal est ouvert et pas terminé --}}
        <div wire:poll.600ms="pollTerminal" x-show="false" aria-hidden="true"></div>

        <div style="width:min(860px,95vw);max-height:90vh;display:flex;flex-direction:column;border-radius:16px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,0.6);border:1px solid rgba(255,255,255,0.08);">

            {{-- Barre titre --}}
            <div style="background:#1e293b;padding:14px 20px;display:flex;align-items:center;gap:12px;flex-shrink:0;border-bottom:1px solid rgba(255,255,255,0.06);">
                {{-- Dots macOS --}}
                <div style="display:flex;gap:6px;">
                    <span style="width:12px;height:12px;border-radius:50%;background:#ef4444;display:inline-block;"></span>
                    <span style="width:12px;height:12px;border-radius:50%;background:#f59e0b;display:inline-block;"></span>
                    <span style="width:12px;height:12px;border-radius:50%;background:#22c55e;display:inline-block;"></span>
                </div>
                <div style="flex:1;text-align:center;">
                    <span style="color:#94a3b8;font-size:0.78rem;font-family:monospace;letter-spacing:0.04em;">
                        tenant:health-check --all
                    </span>
                </div>
                {{-- Spinner / Checkmark --}}
                <div x-show="!done" style="display:flex;align-items:center;gap:6px;">
                    <svg style="width:14px;height:14px;color:#67e8f9;animation:spin 1s linear infinite;flex-shrink:0;" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path style="opacity:.75;fill:#67e8f9" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    <span style="color:#67e8f9;font-size:0.72rem;font-family:monospace;">En cours...</span>
                </div>
                <div x-show="done" x-cloak style="display:flex;align-items:center;gap:6px;">
                    <svg style="width:14px;height:14px;color:#4ade80;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <span style="color:#4ade80;font-size:0.72rem;font-family:monospace;">Terminé</span>
                </div>
            </div>

            {{-- Corps terminal --}}
            <div
                id="health-terminal-body"
                style="background:#0f172a;padding:16px 20px;overflow-y:auto;flex:1;min-height:320px;max-height:60vh;"
            >
                {{-- Prompt initial --}}
                <div style="color:#475569;font-size:0.78rem;font-family:monospace;margin-bottom:8px;">
                    $ php artisan tenant:health-check --all
                </div>

                {{-- Output injecté dynamiquement par Alpine --}}
                <div id="health-terminal-output"></div>

                {{-- Curseur clignotant --}}
                <div x-show="!done" style="display:inline-block;width:8px;height:1em;background:#67e8f9;opacity:.8;vertical-align:middle;margin-top:4px;animation:blink 1s step-end infinite;"></div>
            </div>

            {{-- Pied : bouton Fermer (visible seulement quand terminé) --}}
            <div
                x-show="done"
                x-cloak
                style="background:#1e293b;padding:14px 20px;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;border-top:1px solid rgba(255,255,255,0.06);"
            >
                <button
                    @click="close()"
                    style="background:#334155;color:#e2e8f0;border:none;border-radius:8px;padding:8px 20px;font-size:0.82rem;font-family:monospace;cursor:pointer;transition:background .15s;"
                    onmouseover="this.style.background='#475569'"
                    onmouseout="this.style.background='#334155'"
                >
                    Fermer
                </button>
                <button
                    @click="close()"
                    style="background:#0891b2;color:white;border:none;border-radius:8px;padding:8px 20px;font-size:0.82rem;font-family:monospace;cursor:pointer;transition:background .15s;font-weight:600;"
                    onmouseover="this.style.background='#0e7490'"
                    onmouseout="this.style.background='#0891b2'"
                >
                    ✔ OK, fermer
                </button>
            </div>

        </div>
    </div>
    @endif

    {{-- Page Header --}}
    <div class="flex items-center justify-between mb-8">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Dernière vérification :
            <span class="font-semibold text-gray-700 dark:text-gray-300">
                @if ($lastCheckedAt)
                    <time class="x-timeago" datetime="{{ $lastCheckedAt->toIso8601String() }}">{{ $lastCheckedAt->toIso8601String() }}</time>
                @else
                    Jamais effectuée
                @endif
            </span>
        </p>
        <x-filament::button
            wire:click="runAllChecks"
            :disabled="$terminalOpen"
            color="primary"
            icon="heroicon-o-arrow-path"
            size="md"
        >
            Vérifier tous les tenants
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

                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        wire:model="logRotateDryRun"
                        class="rounded border-gray-300 dark:border-gray-600 text-primary-600"
                    />
                    <span class="text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap">Mode simulation</span>
                </label>

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
                                <time class="x-timeago" datetime="{{ $data['last_check']->toIso8601String() }}">{{ $data['last_check']->toIso8601String() }}</time>
                            @else
                                Jamais vérifié
                            @endif
                        </span>
                        <x-filament::button
                            wire:click="runTenantCheck('{{ $tenant->code }}')"
                            :disabled="$isRunningTenant === $tenant->code"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-60"
                            wire:target="runTenantCheck('{{ $tenant->code }}')"
                            size="xs"
                            color="gray"
                            icon="heroicon-o-arrow-path"
                        >
                            <span wire:loading.remove wire:target="runTenantCheck('{{ $tenant->code }}')">Re-vérifier</span>
                            <span wire:loading wire:target="runTenantCheck('{{ $tenant->code }}')">En cours...</span>
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

    {{-- ═══════════════════════════════════════════════════════════════════
         Scripts
    ═══════════════════════════════════════════════════════════════════ --}}
    <style>
        @keyframes spin  { to { transform: rotate(360deg); } }
        @keyframes blink { 50% { opacity: 0; } }
        [x-cloak] { display: none !important; }
    </style>

    <script>
        /* ── timeAgo ── */
        function timeAgo(date) {
            const diff = Math.floor((new Date() - date) / 1000);
            if (diff < 60)    return 'il y a quelques secondes';
            if (diff < 3600)  { const m = Math.floor(diff / 60);   return `il y a ${m} minute${m > 1 ? 's' : ''}`; }
            if (diff < 86400) { const h = Math.floor(diff / 3600); return `il y a ${h} heure${h > 1 ? 's' : ''}`; }
            const d = Math.floor(diff / 86400);
            return `il y a ${d} jour${d > 1 ? 's' : ''}`;
        }
        function updateTimeAgo() {
            document.querySelectorAll('time.x-timeago').forEach(el => {
                const d = new Date(el.getAttribute('datetime'));
                if (!isNaN(d)) el.textContent = timeAgo(d);
            });
        }
        updateTimeAgo();
        setInterval(updateTimeAgo, 30000);
        document.addEventListener('livewire:navigated',  updateTimeAgo);
        document.addEventListener('livewire:updated',    () => setTimeout(updateTimeAgo, 100));
        document.addEventListener('livewire:morph',      () => setTimeout(updateTimeAgo, 100));
        document.addEventListener('refresh-timeago',     () => setTimeout(updateTimeAgo, 150));

        /* ── Terminal Alpine component ──
         * Reçoit les lignes via l'événement Livewire 'terminal-update'
         * dispatché par pollTerminal() toutes les 600ms.
         */
        function healthTerminal() {
            return {
                done: false,

                init() {
                    this.$nextTick(() => this.scrollBottom());
                },

                onUpdate(detail) {
                    const output = document.getElementById('health-terminal-output');
                    if (output && detail.lines && detail.lines.length > 0) {
                        detail.lines.forEach(html => {
                            const wrapper = document.createElement('div');
                            wrapper.innerHTML = html;
                            output.appendChild(wrapper.firstChild || wrapper);
                        });
                        this.scrollBottom();
                    }
                    if (detail.done) {
                        this.done = true;
                        this.scrollBottom();
                    }
                },

                close() {
                    @this.call('closeTerminal');
                },

                scrollBottom() {
                    const body = document.getElementById('health-terminal-body');
                    if (body) body.scrollTop = body.scrollHeight;
                },
            };
        }
    </script>

</x-filament-panels::page>
