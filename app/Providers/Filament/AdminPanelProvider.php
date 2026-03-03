<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->brandName('KLASSCI Master')
            ->brandLogo(asset('images/LOGO-KLASSCI-PNG.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('images/LOGO-KLASSCI-PNG.png'))
            ->colors([
                // Cyan cohérent avec le thème Graphite Pro
                // Filament génère bg-primary-500/10 text-primary-400 sur les items actifs
                // Ces valeurs cyan garantissent que les classes auto-générées correspondent au CSS
                'primary' => [
                    50  => '#ecfeff',
                    100 => '#cffafe',
                    200 => '#a5f3fc',
                    300 => '#67e8f9',
                    400 => '#22d3ee',
                    500 => '#06b6d4',
                    600 => '#0891b2',
                    700 => '#0e7490',
                    800 => '#155e75',
                    900 => '#164e63',
                    950 => '#083344',
                ],
                'gray' => Color::Slate,
            ])
            // Fonts + Thème CSS Slate Pro
            ->renderHook(
                'panels::styles.after',
                fn () => '<link rel="preconnect" href="https://fonts.googleapis.com">'
                    . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                    . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap">'
                    . '<link rel="stylesheet" href="' . asset('css/klassci-admin-theme.css') . '?v=' . (@filemtime(public_path('css/klassci-admin-theme.css')) ?: time()) . '">'
            )
            // JS : préserver le logo KLASSCI en mode collapsed
            ->renderHook(
                'panels::scripts.after',
                fn () => <<<'HTML'
<script>
(function () {
    'use strict';

    function initCollapsedLogo() {
        var sidebar = document.querySelector('.fi-sidebar');
        var logo    = document.querySelector('.fi-sidebar-header .fi-logo');
        if (!sidebar || !logo) return;

        // Rendre le logo toujours visible, même quand Alpine le cache via x-show
        // Filament utilise x-show="$store.sidebar.isOpen" sur le contenu du logo
        // On force display:flex sur le conteneur et on laisse l'image se redimensionner
        var logoImg = logo.querySelector('img');

        function sync() {
            var isCollapsed = sidebar.offsetWidth < 150;
            if (isCollapsed) {
                // Sidebar collapsed : centrer le logo et le réduire
                logo.style.cssText = 'display:flex!important;align-items:center;justify-content:center;';
                if (logoImg) {
                    logoImg.style.cssText = 'max-height:1.75rem!important;width:auto!important;opacity:1!important;';
                }
            } else {
                // Sidebar ouverte : rétablir
                logo.style.cssText = '';
                if (logoImg) logoImg.style.cssText = '';
            }
        }

        // Observer la largeur via ResizeObserver
        if (window.ResizeObserver) {
            var ro = new ResizeObserver(sync);
            ro.observe(sidebar);
        }

        // Fallback Alpine effect
        if (window.Alpine) {
            document.addEventListener('alpine:initialized', function () {
                try {
                    Alpine.effect(function () {
                        var _ = Alpine.store('sidebar').isOpen;
                        setTimeout(sync, 50);
                    });
                } catch (e) {}
            });
        }

        sync();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCollapsedLogo);
    } else {
        setTimeout(initCollapsedLogo, 80);
    }
})();
</script>
HTML
            )
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                \App\Filament\Widgets\CustomAccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
