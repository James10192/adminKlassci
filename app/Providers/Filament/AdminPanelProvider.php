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
                'primary' => [
                    50  => '#eff6ff',
                    100 => '#dbeafe',
                    200 => '#bfdbfe',
                    300 => '#93c5fd',
                    400 => '#60a5fa',
                    500 => '#3b82f6',
                    600 => '#2563eb',
                    700 => '#1d4ed8',
                    800 => '#1e40af',
                    900 => '#1e3a8a',
                    950 => '#172554',
                ],
                'gray' => Color::Slate,
            ])
            // Thème CSS premium KLASSCI
            ->renderHook(
                'panels::styles.after',
                fn () => '<link rel="preconnect" href="https://fonts.googleapis.com">'
                    . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                    . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">'
                    . '<link rel="stylesheet" href="' . asset('css/klassci-admin-theme.css') . '?v=' . (@filemtime(public_path('css/klassci-admin-theme.css')) ?: time()) . '">'
            )
            // JS : logo visible quand sidebar collapsed + transition smooth
            ->renderHook(
                'panels::scripts.after',
                fn () => <<<'HTML'
<script>
(function() {
    // Attend que le DOM soit prêt
    function initSidebarLogo() {
        const sidebar = document.querySelector('.fi-sidebar');
        if (!sidebar) return;

        // Observer les changements de classe/style sur la sidebar (collapse Filament)
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                if (m.type === 'attributes') {
                    updateLogoState();
                }
            });
        });
        observer.observe(sidebar, { attributes: true, attributeFilter: ['class', 'style'] });

        // Observer Alpine.js store si disponible
        if (window.Alpine) {
            Alpine.effect(function() {
                try {
                    var isOpen = Alpine.store('sidebar').isOpen;
                    updateLogoState(isOpen);
                } catch(e) {}
            });
        }

        function updateLogoState(isOpen) {
            var logo = document.querySelector('.fi-sidebar-header .fi-logo');
            var header = document.querySelector('.fi-sidebar-header');
            if (!logo || !header) return;

            // Supprimer l'indicateur "K" si déjà présent
            var existing = header.querySelector('.klassci-k-logo');
            if (existing) existing.remove();

            // Tenter de détecter si la sidebar est collapsed via Alpine store
            var collapsed = false;
            try {
                collapsed = !Alpine.store('sidebar').isOpen;
            } catch(e) {
                // Fallback : vérifier la largeur
                collapsed = sidebar.offsetWidth < 80;
            }

            if (collapsed) {
                // Afficher l'indicateur "K" centré
                var kLogo = document.createElement('div');
                kLogo.className = 'klassci-k-logo';
                kLogo.textContent = 'K';
                kLogo.style.cssText = [
                    'display:flex',
                    'align-items:center',
                    'justify-content:center',
                    'width:2rem',
                    'height:2rem',
                    'background:linear-gradient(135deg,#6366f1,#4f46e5)',
                    'border-radius:.5rem',
                    'color:white',
                    'font-weight:800',
                    'font-size:.9rem',
                    'font-family:Inter,sans-serif',
                    'letter-spacing:-.02em',
                    'box-shadow:0 2px 8px rgba(99,102,241,.35)',
                    'margin:auto',
                    'cursor:pointer',
                    'transition:transform .15s ease',
                    'flex-shrink:0'
                ].join(';');
                header.appendChild(kLogo);
            }
        }

        // Init
        updateLogoState();

        // Réécouter les clics sur le bouton toggle
        document.addEventListener('click', function(e) {
            if (e.target.closest('.fi-sidebar-close-btn') || e.target.closest('[wire\\:click*="sidebar"]')) {
                setTimeout(updateLogoState, 250);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebarLogo);
    } else {
        setTimeout(initSidebarLogo, 100);
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
