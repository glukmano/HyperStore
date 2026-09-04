<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Marketplace\Models\VendorUser;

/**
 * Minimal first-party web session authentication (Phase-15 completion fix,
 * Phase-17 storefront-customer redirect extension). Reuses the existing
 * `web` guard / `users` Eloquent provider / App\Models\User — no second
 * identity system, no package installation.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('control-center.dashboard');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], $remember)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->isActive()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account is not active.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended($this->postLoginRoute($user));
    }

    /**
     * Storefront customers (no staff/vendor membership, not super admin) land
     * on the storefront home rather than an empty Control Center shell they
     * have no navigation entries for — Control Center authorization and
     * customer identity are deliberately kept distinct (Phase-17).
     */
    private function postLoginRoute(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return route('control-center.dashboard');
        }

        $isStaff = $user->tenantMemberships()->where('is_active', true)->exists()
            || VendorUser::query()->where('user_id', $user->id)->where('is_active', true)->exists();

        return $isStaff ? route('control-center.dashboard') : route('storefront.home');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
