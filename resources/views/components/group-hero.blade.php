@props(['title', 'subtitle' => null, 'iconPath' => null])

<header class="gp-hero">
    <div class="gp-hero-top">
        <div class="gp-hero-left">
            @if($iconPath)
                <div class="gp-hero-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}" />
                    </svg>
                </div>
            @endif
            <div class="gp-hero-text">
                <h1 class="gp-hero-title">{{ $title }}</h1>
                @if($subtitle)
                    <p class="gp-hero-subtitle">{{ $subtitle }}</p>
                @endif
                @isset($badges)
                    <div class="gp-hero-badges">{{ $badges }}</div>
                @endisset
            </div>
        </div>

        @isset($actions)
            <div class="gp-hero-actions">{{ $actions }}</div>
        @endisset
    </div>

    @isset($kpis)
        <div class="gp-hero-kpis">{{ $kpis }}</div>
    @endisset
</header>
