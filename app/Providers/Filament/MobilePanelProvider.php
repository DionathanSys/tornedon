<?php

namespace App\Providers\Filament;

use App\Filament\Mobile\Resources\MobileServiceOrders\MobileServiceOrderResource;
use App\Filament\Mobile\Resources\MobileServices\MobileServiceResource;
use App\Filament\Mobile\Resources\Services\ServiceResource;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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
            ->tenant(Company::class)
            ->tenantRegistration(RegisterCompany::class)
            ->colors([
                'primary' => Color::Zinc,
            ])
            // ->resources([
            //     MobileServiceOrderResource::class,
            //     ServiceResource::class,
            // ])
            ->discoverResources(in: app_path('Filament/Mobile/Resources'), for: 'App\Filament\Mobile\Resources')
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
                'panels::body.end',
                fn () => \Livewire\Livewire::mount('create-error-ticket-action')
            )
            ->resourceCreatePageRedirect('edit')
            ->databaseNotifications();
    }
}
