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
                // Bleu KLASSCI. Le cyan hérité d'un ancien thème colorait en turquoise
                // les liens, les onglets et le libellé du menu actif (illisible sur
                // son fond bleu) : tout ce que Filament dessine en « primary ».
                'primary' => Color::hex('#0453cb'),
                'gray' => Color::Slate,
            ])
            // Fonts + Thème CSS Slate Pro
            // Les initiales sont dessinees en local : le fournisseur par defaut
            // de Filament envoie le nom de la personne a ui-avatars.com a chaque
            // affichage, et casse l avatar des que ce tiers est injoignable.
            ->defaultAvatarProvider(\App\Support\Avatar\InitialesAvatarProvider::class)
            ->renderHook(
                'panels::styles.after',
                fn () => '<link rel="preconnect" href="https://fonts.googleapis.com">'
                    . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                    . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap">'
                    // Utilitaires Tailwind de nos vues Blade, absents de la feuille de
                    // Filament (voir scripts/utilitaires-admin). Avant le thème, qui garde le dernier mot.
                    . '<link rel="stylesheet" href="' . asset('css/klassci-admin-utilities.css') . '?v=' . (@filemtime(public_path('css/klassci-admin-utilities.css')) ?: time()) . '">'
                    . '<link rel="stylesheet" href="' . asset('css/klassci-admin-theme.css') . '?v=' . (@filemtime(public_path('css/klassci-admin-theme.css')) ?: time()) . '">'
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
            // Aucun widget de compte : le nom et la déconnexion sont déjà dans
            // la barre du haut. La carte « Bonjour » occupait toute la largeur,
            // en tête de l'écran le plus consulté, pour n'y rien apprendre.
            ->widgets([])
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
