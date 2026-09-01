<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Contracts\ShippingZoneMatcherInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneAssignment;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateOutcome;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class ShippingChannelRuntimeEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $storeA;

    private Store $storeB;

    private Channel $channelX;

    private ShippingZone $zone;

    private ShippingMethod $method;

    private ShippingZoneMatcherInterface $matcher;

    private ShippingRateEngineInterface $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Channel Test Tenant', 'slug' => 'chan-test', 'status' => 'active']);
        $this->storeA = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'STORE_A', 'name' => 'Store A', 'slug' => 'store-a', 'status' => 'active']);
        $this->storeB = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'STORE_B', 'name' => 'Store B', 'slug' => 'store-b', 'status' => 'active']);

        $this->channelX = Channel::create(['name' => 'Channel X', 'handle' => 'channel-x', 'is_active' => true]);

        $this->zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE_CHAN', 'name' => 'CH Zone Chan', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $this->zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $this->method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M_CHAN_TEST',
            'name' => 'Method Chan Test',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->method->id, 'shipping_zone_id' => $this->zone->id]);

        $this->matcher = app(ShippingZoneMatcherInterface::class);
        $this->engine = app(ShippingRateEngineInterface::class);
    }

    /**
     * Test 1: Channel X enabled for Store A.
     * Assignment: store_id = NULL, channel_id = X
     * Context: Store A + Channel X
     * -> Zone matches -> shipping method eligible
     */
    public function test_channel_x_enabled_for_store_a_matches_tenant_wide_assignment_in_store_a_context(): void
    {
        StoreChannel::create(['store_id' => $this->storeA->id, 'channel_id' => $this->channelX->id, 'is_active' => true]);

        ShippingZoneAssignment::create([
            'shipping_zone_id' => $this->zone->id,
            'store_id' => null,
            'channel_id' => $this->channelX->id,
        ]);

        $dest = new ShippingDestination(countryCode: 'CH');
        $context = new ShippingContext(
            tenantId: $this->tenant->id,
            currency: 'CHF',
            storeId: $this->storeA->id,
            channelId: $this->channelX->id
        );

        $matched = $this->matcher->match($dest, $context);
        $this->assertTrue($matched->contains('id', $this->zone->id), 'Zone MUST match Store A + Channel X');

        $req = new ShippingRateRequest(
            context: $context,
            destination: $dest,
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );
        $result = $this->engine->calculateQuotes($req);
        $this->assertSame(ShippingRateOutcome::SUCCESS, $result->outcome);
        $this->assertCount(1, $result->quotes);
    }

    /**
     * Test 2: Channel X enabled for Store A (NOT Store B).
     * Assignment: store_id = NULL, channel_id = X
     * Context: Store B + Channel X
     * -> Zone DOES NOT match -> shipping method unavailable (NO_METHOD_AVAILABLE)
     */
    public function test_channel_x_not_enabled_for_store_b_does_not_match_tenant_wide_assignment_in_store_b_context(): void
    {
        StoreChannel::create(['store_id' => $this->storeA->id, 'channel_id' => $this->channelX->id, 'is_active' => true]);

        ShippingZoneAssignment::create([
            'shipping_zone_id' => $this->zone->id,
            'store_id' => null,
            'channel_id' => $this->channelX->id,
        ]);

        $dest = new ShippingDestination(countryCode: 'CH');
        $context = new ShippingContext(
            tenantId: $this->tenant->id,
            currency: 'CHF',
            storeId: $this->storeB->id,
            channelId: $this->channelX->id
        );

        $matched = $this->matcher->match($dest, $context);
        $this->assertFalse($matched->contains('id', $this->zone->id), 'Zone MUST NOT match Store B where Channel X is not enabled');

        $req = new ShippingRateRequest(
            context: $context,
            destination: $dest,
            lines: [['product_id' => 1, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(1000, 'CHF'), 'unit_weight' => Weight::zero(), 'is_shippable' => true]]
        );
        $result = $this->engine->calculateQuotes($req);
        $this->assertSame(ShippingRateOutcome::NO_METHOD_AVAILABLE, $result->outcome);
        $this->assertEmpty($result->quotes);
    }

    /**
     * Test 3: Channel X enabled for Store A AND Store B.
     * Assignment: store_id = NULL, channel_id = X
     * -> Both Stores match.
     */
    public function test_channel_x_enabled_for_both_stores_matches_both(): void
    {
        StoreChannel::create(['store_id' => $this->storeA->id, 'channel_id' => $this->channelX->id, 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->storeB->id, 'channel_id' => $this->channelX->id, 'is_active' => true]);

        ShippingZoneAssignment::create([
            'shipping_zone_id' => $this->zone->id,
            'store_id' => null,
            'channel_id' => $this->channelX->id,
        ]);

        $dest = new ShippingDestination(countryCode: 'CH');

        $ctxA = new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF', storeId: $this->storeA->id, channelId: $this->channelX->id);
        $this->assertTrue($this->matcher->match($dest, $ctxA)->contains('id', $this->zone->id));

        $ctxB = new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF', storeId: $this->storeB->id, channelId: $this->channelX->id);
        $this->assertTrue($this->matcher->match($dest, $ctxB)->contains('id', $this->zone->id));
    }

    /**
     * Test 4: Assignment: store_id = Store A, channel_id = X.
     * Context: Store B + Channel X -> no match.
     */
    public function test_store_specific_channel_assignment_rejects_other_stores(): void
    {
        StoreChannel::create(['store_id' => $this->storeA->id, 'channel_id' => $this->channelX->id, 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->storeB->id, 'channel_id' => $this->channelX->id, 'is_active' => true]);

        ShippingZoneAssignment::create([
            'shipping_zone_id' => $this->zone->id,
            'store_id' => $this->storeA->id,
            'channel_id' => $this->channelX->id,
        ]);

        $dest = new ShippingDestination(countryCode: 'CH');
        $ctxB = new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF', storeId: $this->storeB->id, channelId: $this->channelX->id);

        $this->assertFalse($this->matcher->match($dest, $ctxB)->contains('id', $this->zone->id));
    }

    /**
     * Test 5: Assignment: store_id = NULL, channel_id = X.
     * Context: channel X but storeId = NULL -> fail safely / no match.
     */
    public function test_channel_scoped_assignment_with_null_store_context_fails_safely_no_match(): void
    {
        StoreChannel::create(['store_id' => $this->storeA->id, 'channel_id' => $this->channelX->id, 'is_active' => true]);

        ShippingZoneAssignment::create([
            'shipping_zone_id' => $this->zone->id,
            'store_id' => null,
            'channel_id' => $this->channelX->id,
        ]);

        $dest = new ShippingDestination(countryCode: 'CH');
        $ctxNoStore = new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF', storeId: null, channelId: $this->channelX->id);

        $this->assertFalse($this->matcher->match($dest, $ctxNoStore)->contains('id', $this->zone->id));
    }

    /**
     * Test 6: Inactive StoreChannel -> no match.
     */
    public function test_inactive_store_channel_does_not_match(): void
    {
        StoreChannel::create(['store_id' => $this->storeA->id, 'channel_id' => $this->channelX->id, 'is_active' => false]);

        // Model guard check: inactive store-channel cannot even be created
        $this->expectException(\InvalidArgumentException::class);
        ShippingZoneAssignment::create([
            'shipping_zone_id' => $this->zone->id,
            'store_id' => $this->storeA->id,
            'channel_id' => $this->channelX->id,
        ]);
    }

    /**
     * Test 7: Global active Channel but no StoreChannel mapping -> no match.
     */
    public function test_global_active_channel_with_no_store_mapping_rejected(): void
    {
        $unmappedChannel = Channel::create(['name' => 'Unmapped Channel', 'handle' => 'unmapped-chan', 'is_active' => true]);

        $this->expectException(\InvalidArgumentException::class);
        ShippingZoneAssignment::create([
            'shipping_zone_id' => $this->zone->id,
            'store_id' => $this->storeA->id,
            'channel_id' => $unmappedChannel->id,
        ]);
    }
}
