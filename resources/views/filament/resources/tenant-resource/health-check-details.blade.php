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

    {{-- Métadonnées --}}
    @if($record->metadata && is_array($record->metadata) && count($record->metadata) > 0)
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Métadonnées</p>
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4 space-y-2">
            @foreach($record->metadata as $key => $value)
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
