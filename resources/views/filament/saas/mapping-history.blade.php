<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Chaque étape est enregistrée avec son auteur. Une approbation confirme la qualification proposée,
        mais n'importe ni ne déploie d'établissement et ne modifie jamais les droits du groupe.
    </p>
    <ol class="divide-y divide-gray-200 dark:divide-gray-700 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        @forelse ($events as $event)
            <li class="p-4 space-y-1">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-semibold text-gray-900 dark:text-white">{{ match ($event->action) {
                        'draft_saved' => 'Brouillon enregistré',
                        'submitted' => 'Soumis à vérification',
                        'approved' => 'Qualification approuvée',
                        'rejected' => 'Retour pour correction',
                        default => 'Événement du dossier',
                    } }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $event->created_at?->format('d/m/Y à H:i') }}
                    </span>
                </div>
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    {{ $event->actor?->name ?? 'Opérateur non disponible' }}
                    · {{ $event->from_state ?? 'initial' }} → {{ $event->to_state }}
                </p>
                @if (filled($event->details['review_note'] ?? null))
                    <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line break-words">
                        {{ $event->details['review_note'] }}
                    </p>
                @endif
            </li>
        @empty
            <li class="p-4 text-gray-600 dark:text-gray-300">Aucun événement enregistré.</li>
        @endforelse
    </ol>
</div>