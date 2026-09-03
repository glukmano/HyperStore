<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\Stores\Contracts\StoreCreationServiceInterface;
use App\Core\Stores\Services\StoreCreationService;
use App\Core\SuperAdmin\Contracts\ContextualMutationAuthorizerInterface;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\SuperAdmin\Services\TenantResourceEntitlementGuard;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class ControlCenterArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creation_service_depends_on_contextual_mutation_authorizer(): void
    {
        $reflector = new ReflectionClass(StoreCreationService::class);
        $constructor = $reflector->getConstructor();

        $this->assertNotNull($constructor);

        $parameterTypes = array_map(
            fn ($param) => $param->getType()?->getName(),
            $constructor->getParameters()
        );

        $this->assertContains(
            ContextualMutationAuthorizerInterface::class,
            $parameterTypes,
            'StoreCreationService must inject ContextualMutationAuthorizerInterface to enforce atomic membership authorization.'
        );
    }

    public function test_tenant_entitlement_guard_depends_on_tenant_license_service(): void
    {
        $reflector = new ReflectionClass(TenantResourceEntitlementGuard::class);
        $constructor = $reflector->getConstructor();

        $this->assertNotNull($constructor);

        $parameterTypes = array_map(
            fn ($param) => $param->getType()?->getName(),
            $constructor->getParameters()
        );

        $this->assertContains(
            TenantLicenseServiceInterface::class,
            $parameterTypes,
            'TenantResourceEntitlementGuard must inject TenantLicenseServiceInterface to enforce fail-closed license checks under Tenant lock.'
        );
    }

    public function test_contextual_mutation_authorizer_interface_declares_multi_context_methods(): void
    {
        $reflector = new ReflectionClass(ContextualMutationAuthorizerInterface::class);

        $this->assertTrue($reflector->hasMethod('executeTenantAuthorized'));
        $this->assertTrue($reflector->hasMethod('executeStoreAuthorized'));
        $this->assertTrue($reflector->hasMethod('executeVendorAuthorized'));
        $this->assertTrue($reflector->hasMethod('executeSuperAdminAuthorized'));
    }

    public function test_store_creation_by_revoked_member_is_blocked_by_production_authorizer(): void
    {
        $tenant = Tenant::create(['name' => 'Arch Tenant', 'slug' => 'arch-'.uniqid(), 'status' => 'active']);
        $user = User::factory()->create();

        // Create inactive membership
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'admin',
            'is_active' => false,
        ]);

        $storeService = app(StoreCreationServiceInterface::class);

        $this->expectException(UnauthorizedContextException::class);

        $storeService->createStore($tenant->id, ['name' => 'Blocked Store', 'slug' => 'blk-'.uniqid()], $user->id);
    }
}
