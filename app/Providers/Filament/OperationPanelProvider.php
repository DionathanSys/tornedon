<?php

namespace App\Providers\Filament;

use App\Filament\Operation\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Operation\Resources\AccountReceivables\AccountReceivableResource;
use App\Filament\Operation\Resources\CashMovements\CashMovementResource;
use App\Filament\Operation\Resources\ProductionRequests\ProductionRequestResource;
use App\Filament\Operation\Widgets\AccountsChart;
use App\Filament\Operation\Widgets\ProductionRequestChart;
use App\Filament\Operation\Widgets\RevenueChart;
use App\Filament\Operation\Widgets\StatsOverview;
use App\Http\Middleware\AuthorizeTenantPanel;
use App\Models\Company;
use Filament\Enums\DatabaseNotificationsPosition;
use Filament\Facades\Filament;
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
            ->resources([
                ProductionRequestResource::class,
                AccountReceivableResource::class,
                AccountPayableResource::class,
                CashMovementResource::class,
            ])
            ->widgets([
                StatsOverview::class,
                ProductionRequestChart::class,
                RevenueChart::class,
                AccountsChart::class,
            ])
            ->resourceCreatePageRedirect('edit')
            ->discoverPages(
                in: app_path('Filament/Operation/Pages'),
                for: 'App\\Filament\\Operation\\Pages',
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
            ->tenantMiddleware([AuthorizeTenantPanel::class], isPersistent: true)
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => new HtmlString(Filament::getCurrentPanel()?->getId() === 'operation' ? view('filament.operation.head', [
                    'appName' => 'Tornedon Operação',
                    'manifest' => 'manifest-operation.webmanifest',
                ])->render() : '')
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => new HtmlString(Filament::getCurrentPanel()?->getId() === 'operation' ? view('filament.operation.body-end')->render() : '')
            )
            ->databaseNotifications(position: DatabaseNotificationsPosition::Sidebar);
    }
}
