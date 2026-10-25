<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\Bpo\BpoAccessService;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse extends \Filament\Auth\Http\Responses\LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = Filament::auth()->user();
        if ($user instanceof User && Filament::getCurrentPanel()?->getId() !== 'management'
            && app(BpoAccessService::class)->canAccessPanel($user)) {
            $intended = session()->get('url.intended');
            $path = $intended ? parse_url($intended, PHP_URL_PATH) : null;
            // Preserve invitations, signed downloads and already-requested BPO pages.
            if ($path && ! preg_match('#^/(admin|mobile|operation)(/|$)#', $path)) {
                return redirect()->intended(route('filament.bpo.portfolio'));
            }
            session()->forget('url.intended');

            return redirect()->route('filament.bpo.portfolio');
        }

        return parent::toResponse($request);
    }
}
