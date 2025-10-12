<div class="space-y-4">
    {{-- En-tête du backup --}}
    <div class="grid grid-cols-2 gap-4">
        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Type de backup</p>
            <p class="mt-1">
                <x-filament::badge
                    :color="match($record->type) {
                        'full' => 'success',
                        'database_only' => 'info',
                        'files_only' => 'warning',
                        default => 'gray',
                    }">
                    {{ match($record->type) {
                        'full' => '💾 Full Backup',
                        'database_only' => '🗄️ Database Only',
                        'files_only' => '📁 Files Only',
                        default => $record->type,
                    } }}
                </x-filament::badge>
            </p>
        </div>

        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Statut</p>
            <p class="mt-1">
                <x-filament::badge
                    :color="match($record->status) {
                        'completed' => 'success',
                        'in_progress' => 'info',
                        'failed' => 'danger',
                        default => 'gray',
                    }">
                    {{ match($record->status) {
                        'completed' => '✅ Completed',
                        'in_progress' => '⏳ In Progress',
                        'failed' => '❌ Failed',
                        default => $record->status,
                    } }}
                </x-filament::badge>
            </p>
        </div>
    </div>

    {{-- Informations de taille --}}
    <div class="grid grid-cols-3 gap-4">
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">Taille totale</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">
                {{ $record->size_bytes ? number_format($record->size_bytes / 1024 / 1024, 2) : '0' }} MB
            </p>
        </div>

        @if($record->backup_path)
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">Chemin archive</p>
            <p class="mt-1 text-xs font-mono text-gray-700 dark:text-gray-300 break-all">
                {{ basename($record->backup_path) }}
            </p>
        </div>
        @endif

        @if($record->database_backup_path)
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">Base de données</p>
            <p class="mt-1 text-xs font-mono text-gray-700 dark:text-gray-300 break-all">
                {{ basename($record->database_backup_path) }}
            </p>
        </div>
        @endif

        @if($record->storage_backup_path)
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">Fichiers storage</p>
            <p class="mt-1 text-xs font-mono text-gray-700 dark:text-gray-300 break-all">
                {{ basename($record->storage_backup_path) }}
            </p>
        </div>
        @endif
    </div>

    {{-- Dates importantes --}}
    <div class="grid grid-cols-2 gap-4">
        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Créé le</p>
            <p class="mt-1 text-base">{{ $record->created_at->format('d/m/Y H:i:s') }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $record->created_at->diffForHumans() }}</p>
        </div>

        @if($record->expires_at)
        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Expire le</p>
            <p class="mt-1 text-base {{
                $record->expires_at->isPast() ? 'text-red-600 dark:text-red-400 font-semibold' : ''
            }}">
                {{ $record->expires_at->format('d/m/Y H:i:s') }}
            </p>
            <p class="text-xs {{ $record->expires_at->isPast() ? 'text-red-500' : 'text-gray-500 dark:text-gray-400' }}">
                {{ $record->expires_at->isPast() ? '⚠️ Expiré' : $record->expires_at->diffForHumans() }}
            </p>
        </div>
        @endif
    </div>

    {{-- Message d'erreur si échec --}}
    @if($record->status === 'failed' && $record->error_message)
    <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border-2 border-red-200 dark:border-red-800 p-4">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-red-800 dark:text-red-200">Erreur lors du backup</h3>
                <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                    <p class="font-mono">{{ $record->error_message }}</p>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Métadonnées JSON si présentes --}}
    @if($record->metadata && is_array($record->metadata) && count($record->metadata) > 0)
    <div>
        <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">📊 Métadonnées</p>
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4 space-y-2">
            @foreach($record->metadata as $key => $value)
                <div class="flex justify-between text-sm">
                    <span class="font-medium text-gray-600 dark:text-gray-300">
                        {{ ucfirst(str_replace('_', ' ', $key)) }}:
                    </span>
                    <span class="text-gray-900 dark:text-gray-100">
                        @if(is_bool($value))
                            {{ $value ? '✅ Oui' : '❌ Non' }}
                        @elseif(is_array($value))
                            {{ json_encode($value) }}
                        @elseif(is_numeric($value) && $value > 1000000)
                            {{ number_format($value / 1024 / 1024, 2) }} MB
                        @else
                            {{ $value }}
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Actions disponibles --}}
    @if($record->status === 'completed' && !$record->expires_at?->isPast())
    <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Actions disponibles</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Télécharger ou restaurer ce backup
                </p>
            </div>
            <div class="flex gap-2">
                @if($record->backup_path && file_exists($record->backup_path))
                <a href="{{ route('filament.admin.resources.tenants.backups.download', ['tenant' => $record->tenant_id, 'backup' => $record->id]) }}"
                   class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Télécharger
                </a>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- Avertissement si expiré --}}
    @if($record->expires_at && $record->expires_at->isPast())
    <div class="rounded-lg bg-yellow-50 dark:bg-yellow-900/20 border-2 border-yellow-200 dark:border-yellow-800 p-4">
        <div class="flex items-center">
            <svg class="h-5 w-5 text-yellow-600 dark:text-yellow-400 mr-3" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <div>
                <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200">Backup expiré</p>
                <p class="text-xs text-yellow-700 dark:text-yellow-300 mt-1">
                    Ce backup a expiré et sera supprimé automatiquement lors du prochain nettoyage.
                </p>
            </div>
        </div>
    </div>
    @endif
</div>
