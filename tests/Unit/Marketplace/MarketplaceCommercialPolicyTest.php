<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Contracts\MarketplaceCommercialPolicyInterface;
use Modules\Marketplace\Enums\MarketplaceCommercialModel;
use Modules\Marketplace\Enums\MerchantOfRecordRole;
use Modules\Marketplace\Exceptions\MarketplaceCommercialPolicyException;
use Tests\TestCase;

class MarketplaceCommercialPolicyTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceCommercialPolicyInterface $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(MarketplaceCommercialPolicyInterface::class);
    }

    public function test_tenant_default_commercial_model_resolved_when_no_store_override(): void
    {
        $tenant = Tenant::create([
            'name' => 'Marketplace Tenant',
            'slug' => 'mp-tenant',
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'platform_as_merchant_of_record',
                ],
            ],
        ]);

        $model = $this->policy->resolveModel($tenant->id);
        $this->assertSame(MarketplaceCommercialModel::PlatformAsMerchantOfRecord, $model);
        $this->assertTrue($this->policy->doesPlatformCollectCustomerFunds($tenant->id));
        $this->assertTrue($this->policy->doesPlatformOweVendorPayable($tenant->id));
        $this->assertTrue($this->policy->doesPlatformRecognizeCommission($tenant->id));
        $this->assertSame(MerchantOfRecordRole::Platform, $this->policy->merchantOfRecordRole($tenant->id));
    }

    public function test_store_override_takes_precedence_over_tenant_default(): void
    {
        $tenant = Tenant::create([
            'name' => 'Marketplace Tenant',
            'slug' => 'mp-tenant',
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'platform_as_merchant_of_record',
                ],
            ],
        ]);

        $store = Store::create([
            'tenant_id' => $tenant->id,
            'name' => 'Seller Direct Store',
            'slug' => 'seller-direct-store',
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'seller_as_merchant_of_record',
                ],
            ],
        ]);

        $model = $this->policy->resolveModel($tenant->id, $store->id);
        $this->assertSame(MarketplaceCommercialModel::SellerAsMerchantOfRecord, $model);
        $this->assertFalse($this->policy->doesPlatformCollectCustomerFunds($tenant->id, $store->id));
        $this->assertFalse($this->policy->doesPlatformOweVendorPayable($tenant->id, $store->id));
        $this->assertTrue($this->policy->doesPlatformRecognizeCommission($tenant->id, $store->id));
        $this->assertSame(MerchantOfRecordRole::Seller, $this->policy->merchantOfRecordRole($tenant->id, $store->id));
    }

    public function test_missing_commercial_model_fails_closed(): void
    {
        $tenant = Tenant::create([
            'name' => 'Unconfigured Tenant',
            'slug' => 'unconf-tenant',
            'settings' => [],
        ]);

        $this->expectException(MarketplaceCommercialPolicyException::class);
        $this->policy->resolveModel($tenant->id);
    }
}
