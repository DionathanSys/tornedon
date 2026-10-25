<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Bpo\BpoAccessService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class EnsureBpoPanelAccess
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User && app(BpoAccessService::class)->canAccessPanel($user), 403);
        if (! $request->route('tenant')) {
            Filament::setTenant(null);
        }
        if ($request->routeIs('filament.bpo.tenant')) {
            return redirect()->route('filament.bpo.portfolio');
        }

        return $next($request);
    }
}
