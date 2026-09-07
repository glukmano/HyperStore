<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Pre-Production Readiness (C.6): walks every registered NavigationItem
 * and, as an authenticated super-admin, proves it resolves to a real route
 * and never returns a 404/500. Codifies the source audit's already-clean
 * finding (85 registrations, zero orphans) so future navigation additions
 * cannot silently regress it.
 */
class NavigationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_navigation_item_resolves_to_a_real_route(): void
    {
        /** @var NavigationRegistryInterface $registry */
        $registry = app(NavigationRegistryInterface::class);
        $items = $registry->all();

        $this->assertNotEmpty($items, 'The navigation registry must have at least one registered item.');

        $missingRoutes = [];
        foreach ($items as $item) {
            if (! RouteFacade::has($item->routeName)) {
                $missingRoutes[] = "{$item->key} -> {$item->routeName}";
            }
        }

        $this->assertSame([], $missingRoutes, 'Every registered NavigationItem must reference a real, compiled route name.');
    }

    public function test_every_registered_navigation_item_is_reachable_by_a_super_admin_without_a_server_error(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $admin = User::factory()->create(['is_super_admin' => true]);

        // A resolved Tenant context is how every Control Center screen is
        // actually reached in production (custom domain, or an
        // X-Tenant-ID/X-Tenant-Slug header for API/impersonation-style
        // access — see App\Core\Context\Resolvers\TenantResolver). A bare
        // request with no resolvable tenant is not representative of real
        // usage, so this test supplies one exactly the way the existing
        // ControlCenterAuthenticationAndContextTest does.
        $plan = PlatformSaasPlan::create([
            'code' => 'nav-integrity-plan',
            'name' => 'Nav Integrity Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 5],
        ]);
        $tenant = Tenant::create(['name' => 'Nav Integrity Tenant', 'slug' => 'nav-integrity', 'status' => 'active']);
        app(TenantLicenseServiceInterface::class)->assignLicense($tenant->id, $plan->id);
        $store = Store::create(['tenant_id' => $tenant->id, 'code' => 'NAV_INTEGRITY', 'name' => 'Nav Integrity Store', 'slug' => 'nav-integrity-store', 'status' => 'active']);

        /** @var NavigationRegistryInterface $registry */
        $registry = app(NavigationRegistryInterface::class);

        $failures = [];
        foreach ($registry->all() as $item) {
            $url = $item->url();
            if ($url === null) {
                $failures[] = "{$item->key}: routeName [{$item->routeName}] produced no URL (route missing or requires parameters this test cannot supply).";

                continue;
            }

            $response = $this->actingAs($admin)
                ->withHeaders(['X-Tenant-ID' => (string) $tenant->id, 'X-Store-ID' => (string) $store->id])
                ->get($url);
            $status = $response->getStatusCode();

            if ($status >= 500) {
                $failures[] = "{$item->key} ({$url}): server error [{$status}].";
            } elseif ($status === 404) {
                $failures[] = "{$item->key} ({$url}): not found [404].";
            }
            // 2xx/3xx/401/403 are all acceptable — a visible nav item may
            // still correctly gate access by permission; the requirement
            // here is "no broken link", not "every role can view it".
        }

        $this->assertSame([], $failures, "Broken Control Center navigation items found:\n".implode("\n", $failures));
    }
}
