<?php

namespace App\Providers\Filament;

use App\Filament\Bpo\Auth\Login;
use App\Filament\Bpo\Pages\CompanyOverview;
use App\Filament\Bpo\Pages\CompanyTeam;
use App\Filament\Bpo\Pages\DreReport;
use App\Filament\Bpo\Pages\FiscalDocuments;
use App\Filament\Bpo\Pages\Portfolio;
use App\Http\Middleware\AuthorizeBpoCompany;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureBpoPanelAccess;
use App\Models\Company;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class BpoPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('bpo')->path('bpo')->brandName('Tornedon BPO')
            ->login(Login::class)->passwordReset()->tenant(Company::class)->tenantRoutePrefix('empresas')
            ->navigation(false)->topbar(false)->tenantMenu(false)->darkMode()->defaultThemeMode(ThemeMode::System)
            ->colors(['primary' => [50 => '#effaf8', 100 => '#d8f1ed', 200 => '#b2e3dd', 300 => '#79cbc6', 400 => '#35adb2', 500 => '#16929d', 600 => '#087f8c', 700 => '#096572', 800 => '#0d505d', 900 => '#10434e', 950 => '#062a34'], 'gray' => Color::Slate])
            ->viteTheme('resources/css/filament/bpo/theme.css')
            ->pages([CompanyOverview::class, CompanyTeam::class, FiscalDocuments::class, DreReport::class])
            ->discoverResources(in: app_path('Filament/Bpo/Resources'), for: 'App\\Filament\\Bpo\\Resources')
            ->authenticatedRoutes(function (): void {
                Route::get('/carteira', Portfolio::class)->name('portfolio');
            })
            ->middleware([
                EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, AuthenticateSession::class,
                ShareErrorsFromSession::class, VerifyCsrfToken::class, SubstituteBindings::class,
                DisableBladeIconComponents::class, DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class, EnsureActiveUser::class, EnsureBpoPanelAccess::class], isPersistent: true)
            ->tenantMiddleware([AuthorizeBpoCompany::class], isPersistent: true);
    }
}
