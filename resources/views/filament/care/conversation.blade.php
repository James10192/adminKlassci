@php
    $messages = $getRecord()->messages->filter(fn ($m) => in_array($m->visibility->value, $visibles, true));
@endphp
<div class="space-y-3">
    @forelse ($messages as $m)
        @php $public = $m->visibility === \App\Domain\Care\Tickets\Enums\VisibiliteMessage::PublicClient; @endphp
        <div class="rounded-lg border p-3 {{ $public ? 'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' : 'border-amber-300 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' }}">
            <div class="mb-1 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $m->author_name ?? 'Système' }}</span>
                <span>
                    <x-filament::badge :color="$public ? 'success' : 'warning'" size="sm">{{ $m->visibility->libelle() }}</x-filament::badge>
                    · {{ $m->created_at?->format('d/m/Y H:i') }}
                </span>
            </div>
            <div class="whitespace-pre-line text-sm text-gray-800 dark:text-gray-100">{{ $m->body }}</div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">Aucun message pour l'instant.</p>
    @endforelse
</div>
