<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Bpo\BpoAccessService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

class AuthorizeBpoCompany
{
    public function handle(Request $request, Closure $next): mixed
    {
        $company = Filament::getTenant();
        $user = Filament::auth()->user();
        abort_unless($company instanceof Company && $user && app(BpoAccessService::class)->canAccessCompany($user, $company), 403);

        return $next($request);
    }
}
