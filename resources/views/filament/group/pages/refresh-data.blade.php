<x-filament-panels::page>
    <div class="text-center py-12">
        <div class="w-16 h-16 rounded-full bg-primary-500/10 flex items-center justify-center mx-auto mb-4">
            <x-heroicon-o-arrow-path class="w-8 h-8 text-primary-500" />
        </div>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">
            Actualiser les données
        </h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md mx-auto mb-6">
            Les données sont automatiquement mises en cache pendant 15 minutes.
            Cliquez sur le bouton ci-dessus pour forcer un rafraîchissement immédiat.
        </p>
        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gray-50 dark:bg-gray-700/50 text-sm text-gray-600 dark:text-gray-400">
            <x-heroicon-o-clock class="w-4 h-4" />
            Cache TTL : 15 minutes
        </div>
    </div>
</x-filament-panels::page>
