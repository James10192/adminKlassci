@if(config('group_portal.period_selector_enabled'))
    <div class="gp-topbar-slot">
        @livewire(\App\Livewire\Group\PortalPeriodSelector::class)
    </div>
@endif
