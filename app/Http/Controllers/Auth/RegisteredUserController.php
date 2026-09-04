<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Context\ContextManager;
use App\Core\Customers\CustomerScopeService;
use App\Core\Stores\Models\Store;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Modules\Customers\Services\CustomerProfileService;

/**
 * Storefront customer self-registration (Phase-17 identity prerequisite).
 * Reuses the existing `web` guard / `users` Eloquent provider / App\Models\User
 * exactly as AuthenticatedSessionController does — no second identity system.
 * Never creates a TenantUser/VendorUser row; a registrant is a plain customer.
 *
 * Also grants store-level customer access via the pre-existing (previously
 * unwired) App\Core\Customers\CustomerScopeService — closing the gap between
 * the Foundation-phase tenant_wide/store_isolated customer-account-scope
 * policy and an actual registration flow, which did not exist until now.
 */
class RegisteredUserController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('storefront.home');
        }

        return view('auth.register');
    }

    public function store(
        Request $request,
        ContextManager $contextManager,
        CustomerScopeService $customerScope,
        CustomerProfileService $customerProfileService,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        if ($contextManager->hasStore()) {
            $store = Store::find($contextManager->getStore()->getId());
            if ($store !== null) {
                $customerScope->grantStoreCustomerAccess($user, $store);
            }
        }

        if ($contextManager->hasTenant()) {
            $customerProfileService->firstOrCreateFor($user);
        }

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('storefront.home');
    }
}
