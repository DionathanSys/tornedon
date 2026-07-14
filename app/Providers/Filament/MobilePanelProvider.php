<?php

namespace App\Providers\Filament;

use App\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\HtmlString;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class MobilePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->maxContentWidth(Width::Full)
            ->sidebarFullyCollapsibleOnDesktop()
            ->sidebarWidth('15rem')
            ->id('mobile')
            ->path('mobile')
            ->login()
            ->profile()
            ->tenant(Company::class)
            ->colors([
                'primary' => Color::Zinc,
            ])
            ->discoverResources(
                in: app_path('Filament/Mobile/Resources'),
                for: 'App\\Filament\\Mobile\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Mobile/Pages'),
                for: 'App\\Filament\\Mobile\\Pages',
            )
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
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => new HtmlString(view('filament.mobile.head', [
                    'appName' => 'Tornedon Mobile',
                    'manifest' => 'manifest-mobile.webmanifest',
                ])->render())
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => new HtmlString(view('filament.mobile.body-end')->render())
            )
            ->resourceCreatePageRedirect('edit')
            ->databaseNotifications();
    }
}
