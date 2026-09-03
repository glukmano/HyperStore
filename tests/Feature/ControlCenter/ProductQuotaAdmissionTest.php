<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\TenantResourceQuotaExceededException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Actions\ArchiveProductAction;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\Actions\UpdateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Tests\TestCase;

class ProductQuotaAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CreateProductAction $createAction;

    private ArchiveProductAction $archiveAction;

    private UpdateProductAction $updateAction;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = PlatformSaasPlan::create([
            'code' => 'product-quota-plan',
            'name' => 'Product Quota Plan',
            'status' => 'active',
            'limits' => ['max_products' => 2],
        ]);

        $this->tenant = Tenant::create(['name' => 'Product Quota Tenant', 'slug' => 'pq-tenant', 'status' => 'active']);
        app(TenantLicenseServiceInterface::class)->assignLicense($this->tenant->id, $plan->id);

        $this->createAction = app(CreateProductAction::class);
        $this->archiveAction = app(ArchiveProductAction::class);
        $this->updateAction = app(UpdateProductAction::class);
    }

    public function test_product_creation_and_unarchive_quota_lifecycle(): void
    {
        $dto1 = new ProductData(
            tenantId: $this->tenant->id,
            productType: 'simple',
            sku: 'PROD-1',
            translations: ['en' => ['name' => 'Prod 1']],
            status: 'active'
        );
        $prod1 = $this->createAction->execute($dto1);
        $this->assertNotNull($prod1);

        $dto2 = new ProductData(
            tenantId: $this->tenant->id,
            productType: 'simple',
            sku: 'PROD-2',
            translations: ['en' => ['name' => 'Prod 2']],
            status: 'draft'
        );
        $prod2 = $this->createAction->execute($dto2);
        $this->assertNotNull($prod2);

        // 3rd product creation fails closed (quota 2 reached)
        $dto3 = new ProductData(
            tenantId: $this->tenant->id,
            productType: 'simple',
            sku: 'PROD-3',
            translations: ['en' => ['name' => 'Prod 3']],
            status: 'draft'
        );

        $thrown = false;
        try {
            $this->createAction->execute($dto3);
        } catch (TenantResourceQuotaExceededException) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'Must fail closed when catalog quota is exhausted.');

        // Archiving product 1 decreases quota (usage drops to 1)
        $this->archiveAction->execute($prod1);

        // Now creating product 3 succeeds!
        $prod3 = $this->createAction->execute($dto3);
        $this->assertNotNull($prod3);

        // Un-archiving product 1 when at limit (usage is 2) must fail closed!
        $unarchiveDto = new ProductData(
            tenantId: $this->tenant->id,
            productType: $prod1->product_type,
            sku: $prod1->sku,
            translations: ['en' => ['name' => 'Prod 1 Unarchived']],
            status: 'active' // transitioning from archived -> active is USAGE INCREASE
        );

        $this->expectException(TenantResourceQuotaExceededException::class);
        $this->updateAction->execute($prod1, $unarchiveDto);
    }
}
