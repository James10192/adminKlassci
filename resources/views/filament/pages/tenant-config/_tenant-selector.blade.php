{{-- Sélecteur de tenant (partagé par toutes les pages TenantConfig) --}}
<div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 mb-6">
    <div class="flex items-center gap-4">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
            Établissement :
        </label>
        <select
            wire:model.live="selectedTenantId"
            class="fi-select-input block w-full max-w-md rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
        >
            <option value="">— Sélectionner un tenant —</option>
            @foreach ($tenants as $t)
                <option value="{{ $t['id'] }}">{{ $t['name'] }} ({{ $t['code'] }})</option>
            @endforeach
        </select>
    </div>
</div>
