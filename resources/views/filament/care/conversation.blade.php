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
@php $pieces = $getRecord()->piecesJointes->filter(fn ($p) => in_array($p->visibility->value, $visibles, true)); @endphp
@if ($pieces->isNotEmpty())
    <div class="mt-4 border-t border-gray-200 pt-3 dark:border-white/10">
        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Pièces jointes</p>
        <ul class="space-y-1">
            @foreach ($pieces as $p)
                <li class="flex items-center justify-between gap-3 text-sm">
                    <a href="{{ \App\Http\Controllers\Care\PieceJointeSupportController::lien($p) }}" target="_blank" rel="noopener"
                       class="truncate font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $p->original_name }}</a>
                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                        {{ $p->author_name ?? 'École' }} · {{ number_format($p->size_bytes / 1024, 0, ',', ' ') }} Ko · {{ $p->created_at?->format('d/m/Y H:i') }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
