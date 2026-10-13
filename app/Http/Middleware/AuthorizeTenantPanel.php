<?php

namespace App\Http\Middleware;

use App\Services\Access\UserAccessService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class AuthorizeTenantPanel
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();
        abort_unless($tenant && $user && app(UserAccessService::class)->canAccessPanel($user, $tenant, Filament::getCurrentPanel()->getId()), 403);

        return $next($request);
    }
}
