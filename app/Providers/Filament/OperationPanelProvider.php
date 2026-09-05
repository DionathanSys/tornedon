<?php

namespace App\Providers\Filament;

use App\Models\Company;
use Filament\Enums\DatabaseNotificationsPosition;
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
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class OperationPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->maxContentWidth(Width::Full)
            ->id('operation')
            ->path('operation')
            ->login()
            ->profile()
            ->tenant(Company::class)
            ->topbar(false)
            ->navigation(false)
            ->colors([
                'primary' => Color::Zinc,
            ])
            ->discoverPages(
                in: app_path('Filament/Operation/Pages'),
                for: 'App\\Filament\\Operation\\Pages',
            )
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn () => new HtmlString(view('filament.operation.tenant-switcher')->render()),
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
                fn () => new HtmlString(view('filament.operation.head', [
                    'appName' => 'Tornedon Operação',
                    'manifest' => 'manifest-operation.webmanifest',
                ])->render())
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => new HtmlString(view('filament.operation.body-end')->render())
            )
            ->databaseNotifications(position: DatabaseNotificationsPosition::Sidebar);
    }
}
