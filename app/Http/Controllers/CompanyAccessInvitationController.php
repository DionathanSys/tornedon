<?php

namespace App\Http\Controllers;

use App\Models\CompanyAccessInvitation;
use App\Models\User;
use App\Services\Access\CompanyTeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class CompanyAccessInvitationController extends Controller
{
    public function show(Request $request, string $token)
    {
        $invitation = CompanyAccessInvitation::where('token_hash', hash('sha256', $token))->firstOrFail();
        if ($request->user()) {
            abort_unless($request->user()->isActive() && mb_strtolower($request->user()->email) === $invitation->email, 403);
        }
        abort_if($invitation->accepted_at || $invitation->revoked_at || $invitation->expires_at->isPast(), 410);
        $hasAccount = User::whereRaw('LOWER(email) = ?', [$invitation->email])->exists();
        if (! $request->user()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return view('access.invitation', ['invitation' => $invitation, 'token' => $token, 'hasAccount' => $hasAccount]);
    }

    public function accept(Request $request, string $token, CompanyTeamService $service)
    {
        if ($request->user()) {
            $company = $service->accept($request->user(), $token);
        } else {
            $request->validate(['name' => ['required', 'string', 'max:255'], 'password' => ['required', 'confirmed', Password::min(8)]]);
            [$user, $company] = DB::transaction(function () use ($request, $service, $token): array {
                $invitation = CompanyAccessInvitation::where('token_hash', hash('sha256', $token))->firstOrFail();
                abort_if(User::whereRaw('LOWER(email) = ?', [$invitation->email])->exists(), 403, 'Entre com sua conta para aceitar.');
                $user = User::create([
                    'name' => $request->string('name')->toString(), 'email' => $invitation->email,
                    'password' => $request->input('password'), 'is_active' => true, 'is_admin' => false, 'management_role' => null,
                ]);

                return [$user, $service->accept($user, $token)];
            });
            Auth::login($user);
            $request->session()->regenerate();
        }

        return view('access.invitation-accepted', ['company' => $company]);
    }
}
