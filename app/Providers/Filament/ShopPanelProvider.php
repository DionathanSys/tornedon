<?php

namespace App\Providers\Filament;

use App\Filament\Shop\Pages\ShopDashboard;
use App\Filament\Shop\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Shop\Resources\CashMovements\CashMovementResource;
use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use App\Filament\Shop\Widgets\AccountsChart;
use App\Filament\Shop\Widgets\ProductionRequestChart;
use App\Filament\Shop\Widgets\RevenueChart;
use App\Filament\Shop\Widgets\StatsOverview;
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
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ShopPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->maxContentWidth(Width::Full)
            ->sidebarFullyCollapsibleOnDesktop()
            ->sidebarWidth('15rem')
            ->id('shop')
            ->path('shop')
            ->login()
            ->profile()
            ->tenant(Company::class)
            ->colors([
                'primary' => Color::Zinc,
            ])
            ->resources([
                ProductionRequestResource::class,
                AccountReceivableResource::class,
                CashMovementResource::class,
                AccountPayableResource::class,
            ])
            ->pages([
                ShopDashboard::class,
            ])
            ->widgets([
                StatsOverview::class,
                ProductionRequestChart::class,
                RevenueChart::class,
                AccountsChart::class,
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
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => new HtmlString(view('filament.mobile.head')->render())
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => new HtmlString(view('filament.mobile.body-end')->render())
            )
            ->resourceCreatePageRedirect('edit')
            ->databaseNotifications()
            ->navigationGroups([
                'Vendas',
                'Financeiro',
            ]);
    }
}
