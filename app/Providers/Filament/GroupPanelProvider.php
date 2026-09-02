<?php

namespace App\Providers\Filament;

use App\Filament\Group\Pages\Auth\GroupLogin;
use App\Filament\Group\Pages\EditProfile;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Services\Group\GroupBranding;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class GroupPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        \App\Services\SsoSecretValidator::validate();
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('group')
            ->path('groupe')
            ->login(GroupLogin::class)
            ->passwordReset()
            ->profile(EditProfile::class)
            ->authGuard('group')
            // Résolus par requête, pas au démarrage : le portail doit porter
            // le nom et le logo du groupe connecté, et retomber sur la marque
            // KLASSCI quand le groupe n'a rien déposé (ou hors session, sur la
            // page de connexion). Voir App\Services\Group\GroupBranding.
            ->brandName(fn () => app(GroupBranding::class)->name())
            ->brandLogo(fn () => app(GroupBranding::class)->logoUrl())
            ->brandLogoHeight(fn () => app(GroupBranding::class)->logoHeight())
            ->favicon(fn () => app(GroupBranding::class)->faviconUrl())
            ->colors([
                'primary' => [
                    50  => '#eff6ff',
                    100 => '#dbeafe',
                    200 => '#bfdbfe',
                    300 => '#93c5fd',
                    400 => '#60a5fa',
                    500 => '#0453cb',
                    600 => '#0343a2',
                    700 => '#023379',
                    800 => '#012350',
                    900 => '#011327',
                    950 => '#000a14',
                ],
                'gray' => Color::Slate,
            ])
            ->renderHook(
                'panels::styles.after',
                fn () => '<link rel="preconnect" href="https://fonts.googleapis.com">'
                    . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                    . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap">'
                    . '<link rel="stylesheet" href="' . asset('css/groupe-portal.css') . '?v=' . (@filemtime(public_path('css/groupe-portal.css')) ?: time()) . '">'
                    // La couleur du groupe est injectée après la feuille de
                    // style, en variable CSS : la palette Filament est figée au
                    // démarrage du panel et ne peut pas varier par groupe,
                    // alors que --gp-primary, elle, pilote déjà tout le portail.
                    . '<style>:root{--gp-primary:' . e(app(GroupBranding::class)->primaryHex()) . ';}</style>'
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn () => view('filament.group.partials.topbar-period'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn () => view('filament.group.partials.subscription-banner'),
            )
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->discoverResources(in: app_path('Filament/Group/Resources'), for: 'App\\Filament\\Group\\Resources')
            ->discoverPages(in: app_path('Filament/Group/Pages'), for: 'App\\Filament\\Group\\Pages')
            ->pages([
                \App\Filament\Group\Pages\GroupDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Group/Widgets'), for: 'App\\Filament\\Group\\Widgets')
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
                EnsurePasswordChanged::class,
            ]);
    }
}
