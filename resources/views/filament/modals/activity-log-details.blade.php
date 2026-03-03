<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <p class="text-gray-500 font-medium">Établissement</p>
            <p class="font-semibold">{{ $record->tenant?->name ?? '—' }}</p>
        </div>
        <div>
            <p class="text-gray-500 font-medium">Action</p>
            <p class="font-semibold">{{ $record->action }}</p>
        </div>
        <div>
            <p class="text-gray-500 font-medium">Date</p>
            <p>{{ $record->performed_at?->format('d/m/Y H:i:s') ?? '—' }}</p>
        </div>
        <div>
            <p class="text-gray-500 font-medium">IP</p>
            <p>{{ $record->ip_address ?? '—' }}</p>
        </div>
    </div>

    <div>
        <p class="text-gray-500 font-medium">Description</p>
        <p>{{ $record->description }}</p>
    </div>

    @if($record->metadata)
    <div>
        <p class="text-gray-500 font-medium mb-1">Métadonnées</p>
        <pre class="bg-gray-50 dark:bg-gray-900 rounded p-3 text-xs overflow-auto max-h-60 border border-gray-200 dark:border-gray-700">{{ json_encode($record->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    @endif
</div>
