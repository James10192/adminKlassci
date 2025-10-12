<div class="space-y-4">
    {{-- En-tête du check --}}
    <div class="grid grid-cols-2 gap-4">
        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Type de vérification</p>
            <p class="mt-1 text-lg font-semibold">
                {{ str_replace('_', ' ', ucfirst($record->check_type)) }}
            </p>
        </div>

        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Statut</p>
            <p class="mt-1">
                <x-filament::badge
                    :color="match($record->status) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'unhealthy' => 'danger',
                        default => 'gray',
                    }">
                    {{ match($record->status) {
                        'healthy' => '✅ Healthy',
                        'degraded' => '⚠️ Degraded',
                        'unhealthy' => '❌ Unhealthy',
                        default => $record->status,
                    } }}
                </x-filament::badge>
            </p>
        </div>
    </div>

    {{-- Détails --}}
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Détails</p>
        <p class="mt-1 text-base">{{ $record->details ?? 'N/A' }}</p>
    </div>

    {{-- Temps de réponse --}}
    @if($record->response_time_ms)
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Temps de réponse</p>
        <p class="mt-1 text-base">{{ $record->response_time_ms }} ms</p>
    </div>
    @endif

    {{-- Métadonnées enrichies pour Application Errors --}}
    @if($record->check_type === 'application_errors' && isset($record->metadata['recent_errors']) && count($record->metadata['recent_errors']) > 0)
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">📋 Erreurs récentes (Top 5)</p>
        <div class="space-y-3">
            @foreach($record->metadata['recent_errors'] as $error)
                <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3 border-l-4 {{
                    match($error['level']) {
                        'EMERGENCY', 'ALERT' => 'border-red-600',
                        'CRITICAL' => 'border-orange-600',
                        'ERROR' => 'border-yellow-600',
                        'WARNING' => 'border-blue-600',
                        default => 'border-gray-400'
                    }
                }}">
                    <div class="flex items-start justify-between mb-2">
                        <span class="text-xs font-semibold px-2 py-1 rounded {{
                            match($error['level']) {
                                'EMERGENCY', 'ALERT' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                'CRITICAL' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
                                'ERROR' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                'WARNING' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                                default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
                            }
                        }}">
                            {{ $error['level'] }}
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $error['timestamp'] }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-300 font-mono break-words">{{ $error['message'] }}</p>
                    <div class="mt-2">
                        <span class="text-xs px-2 py-1 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                            📂 {{ ucfirst(str_replace('_', ' ', $error['category'])) }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Statistiques par niveau --}}
    @if(isset($record->metadata['errors_by_level']) && count($record->metadata['errors_by_level']) > 0)
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">📊 Répartition par niveau ({{ $record->metadata['time_window'] ?? '24h' }})</p>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
            @foreach($record->metadata['errors_by_level'] as $level => $count)
                <div class="rounded-lg p-3 {{
                    match($level) {
                        'EMERGENCY', 'ALERT' => 'bg-red-50 dark:bg-red-900/20',
                        'CRITICAL' => 'bg-orange-50 dark:bg-orange-900/20',
                        'ERROR' => 'bg-yellow-50 dark:bg-yellow-900/20',
                        'WARNING' => 'bg-blue-50 dark:bg-blue-900/20',
                        default => 'bg-gray-50 dark:bg-gray-800'
                    }
                }}">
                    <div class="text-2xl font-bold {{
                        match($level) {
                            'EMERGENCY', 'ALERT' => 'text-red-600 dark:text-red-400',
                            'CRITICAL' => 'text-orange-600 dark:text-orange-400',
                            'ERROR' => 'text-yellow-600 dark:text-yellow-400',
                            'WARNING' => 'text-blue-600 dark:text-blue-400',
                            default => 'text-gray-600 dark:text-gray-400'
                        }
                    }}">{{ $count }}</div>
                    <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">{{ $level }}</div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Statistiques par catégorie --}}
    @if(isset($record->metadata['errors_by_category']) && count($record->metadata['errors_by_category']) > 0)
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">🏷️ Répartition par catégorie</p>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
            @foreach($record->metadata['errors_by_category'] as $category => $count)
                <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $count }}</div>
                    <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                        {{ match($category) {
                            'sql' => '💾 SQL Errors',
                            'php_exception' => '🐘 PHP Exceptions',
                            'http' => '🌐 HTTP Errors',
                            'queue' => '📮 Queue Errors',
                            default => '📄 Autres'
                        } }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Score critique --}}
    @if(isset($record->metadata['critical_score']))
    <div class="rounded-lg p-4 {{
        $record->metadata['critical_score'] > 100 ? 'bg-red-50 dark:bg-red-900/20 border-2 border-red-200 dark:border-red-800' :
        ($record->metadata['critical_score'] > 50 ? 'bg-orange-50 dark:bg-orange-900/20 border-2 border-orange-200 dark:border-orange-800' :
        'bg-green-50 dark:bg-green-900/20 border-2 border-green-200 dark:border-green-800')
    }}">
        <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Score de criticité</span>
            <span class="text-2xl font-bold {{
                $record->metadata['critical_score'] > 100 ? 'text-red-600 dark:text-red-400' :
                ($record->metadata['critical_score'] > 50 ? 'text-orange-600 dark:text-orange-400' :
                'text-green-600 dark:text-green-400')
            }}">
                {{ $record->metadata['critical_score'] }}
            </span>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
            Pondération: EMERGENCY×10 + ALERT×8 + CRITICAL×5 + ERROR×2 + WARNING×1
        </p>
    </div>
    @endif
    @endif

    {{-- Métadonnées génériques (pour autres types de checks) --}}
    @if($record->metadata && is_array($record->metadata) && count($record->metadata) > 0 && $record->check_type !== 'application_errors')
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Métadonnées</p>
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4 space-y-2">
            @foreach($record->metadata as $key => $value)
                @if(!in_array($key, ['recent_errors', 'errors_by_level', 'errors_by_category', 'critical_score']))
                <div class="flex justify-between text-sm">
                    <span class="font-medium text-gray-600 dark:text-gray-300">{{ ucfirst(str_replace('_', ' ', $key)) }}:</span>
                    <span class="text-gray-900 dark:text-gray-100">
                        @if(is_bool($value))
                            {{ $value ? 'Oui' : 'Non' }}
                        @elseif(is_array($value))
                            {{ json_encode($value) }}
                        @else
                            {{ $value }}
                        @endif
                    </span>
                </div>
                @endif
            @endforeach
        </div>
    </div>
    @endif

    {{-- Horodatage --}}
    <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <p class="font-medium text-gray-500 dark:text-gray-400">Vérifié le</p>
                <p class="mt-1">{{ $record->checked_at->format('d/m/Y H:i:s') }}</p>
            </div>
            <div>
                <p class="font-medium text-gray-500 dark:text-gray-400">Il y a</p>
                <p class="mt-1">{{ $record->checked_at->diffForHumans() }}</p>
            </div>
        </div>
    </div>
</div>
