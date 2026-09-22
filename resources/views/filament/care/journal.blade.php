<ol class="space-y-2 text-sm">
    @foreach ($getRecord()->events as $e)
        <li class="flex gap-3">
            <span class="w-32 shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $e->created_at?->format('d/m H:i:s') }}</span>
            <span class="text-gray-800 dark:text-gray-100">
                <span class="font-medium">{{ $e->type->value }}</span>
                @if ($e->from_value || $e->to_value)
                    <span class="font-mono text-xs">{{ $e->from_value ?? '∅' }} → {{ $e->to_value ?? '∅' }}</span>
                @endif
                <span class="text-gray-500 dark:text-gray-400">· {{ $e->actor_type->value }}{{ $e->actor_ref ? ' #'.$e->actor_ref : '' }}</span>
                @if ($e->reason)
                    <span class="block text-gray-600 dark:text-gray-300">« {{ $e->reason }} »</span>
                @endif
            </span>
        </li>
    @endforeach
</ol>
