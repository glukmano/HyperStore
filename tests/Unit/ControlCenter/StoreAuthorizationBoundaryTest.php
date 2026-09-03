<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\Stores\Models\Store;
use App\Core\Stores\Models\StoreUser;
use App\Core\SuperAdmin\Contracts\ContextualMutationAuthorizerInterface;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private ContextualMutationAuthorizerInterface $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store 1',
            'slug' => 'store-1',
            'status' => 'active',
        ]);

        $this->authorizer = app(ContextualMutationAuthorizerInterface::class);
    }

    public function test_active_store_user_with_required_role_is_allowed(): void
    {
        $user = User::factory()->create();

        StoreUser::create([
            'store_id' => $this->store->id,
            'user_id' => $user->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $executed = $this->authorizer->executeStoreAuthorized(
            $this->tenant->id,
            $this->store->id,
            $user->id,
            'admin',
            fn () => 'MUTATION_SUCCESS'
        );

        $this->assertSame('MUTATION_SUCCESS', $executed);
    }

    public function test_unrelated_store_user_is_denied(): void
    {
        $otherStore = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Store 2',
            'slug' => 'store-2',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        // User belongs to otherStore, not this->store
        StoreUser::create([
            'store_id' => $otherStore->id,
            'user_id' => $user->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage("Active StoreUser membership required for Store [{$this->store->id}].");

        $this->authorizer->executeStoreAuthorized(
            $this->tenant->id,
            $this->store->id,
            $user->id,
            'admin',
            fn () => 'SHOULD_NOT_EXECUTE'
        );
    }

    public function test_ordinary_tenant_staff_denied_unless_explicitly_entitled_with_store_user(): void
    {
        $staffUser = User::factory()->create();

        // Has ordinary tenant staff role, but no StoreUser row
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $staffUser->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage("Active StoreUser membership required for Store [{$this->store->id}].");

        $this->authorizer->executeStoreAuthorized(
            $this->tenant->id,
            $this->store->id,
            $staffUser->id,
            'admin',
            fn () => 'SHOULD_NOT_EXECUTE'
        );
    }

    public function test_tenant_owner_and_admin_inherit_authority_over_all_stores(): void
    {
        $ownerUser = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $ownerUser->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $executedOwner = $this->authorizer->executeStoreAuthorized(
            $this->tenant->id,
            $this->store->id,
            $ownerUser->id,
            'admin',
            fn () => 'OWNER_INHERITED'
        );

        $this->assertSame('OWNER_INHERITED', $executedOwner);

        $adminUser = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $adminUser->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $executedAdmin = $this->authorizer->executeStoreAuthorized(
            $this->tenant->id,
            $this->store->id,
            $adminUser->id,
            'admin',
            fn () => 'ADMIN_INHERITED'
        );

        $this->assertSame('ADMIN_INHERITED', $executedAdmin);
    }

    public function test_cross_tenant_store_is_rejected(): void
    {
        $otherTenant = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $user = User::factory()->create();

        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage("Store [{$this->store->id}] does not belong to Tenant [{$otherTenant->id}].");

        // Attempting to access Store (which belongs to Tenant A) under Tenant B
        $this->authorizer->executeStoreAuthorized(
            $otherTenant->id,
            $this->store->id,
            $user->id,
            'admin',
            fn () => 'SHOULD_NOT_EXECUTE'
        );
    }
}
