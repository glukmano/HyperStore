<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Shipping\Contracts\ShippingZoneMatcherInterface;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Tests\TestCase;

class ShippingZoneMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ShippingZoneMatcherInterface $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Shipping Test Tenant', 'slug' => 'ship-test', 'status' => 'active']);
        $this->matcher = app(ShippingZoneMatcherInterface::class);
    }

    public function test_specificity_precedence_postal_exact_beats_country_and_global(): void
    {
        // Global zone
        $globalZone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'GLOBAL', 'name' => 'Global', 'priority' => 0, 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $globalZone->id, 'rule_type' => 'broad_global']);

        // Country zone (CH)
        $chZone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'SWITZERLAND', 'name' => 'Switzerland', 'priority' => 0, 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $chZone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        // Exact postal zone (8001 Zurich)
        $zurichZone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'ZURICH_8001', 'name' => 'Zurich 8001', 'priority' => 0, 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zurichZone->id, 'rule_type' => 'postal_exact', 'country_code' => 'CH', 'postal_code_pattern' => '8001']);

        $destination = new ShippingDestination(countryCode: 'CH', postalCode: '8001');
        $context = new ShippingContext(tenantId: $this->tenant->id);

        $matched = $this->matcher->match($destination, $context);

        $this->assertNotEmpty($matched);
        $this->assertSame('ZURICH_8001', $matched->first()?->code);
    }

    public function test_exclusion_rule_beats_inclusion(): void
    {
        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'EU_ZONE', 'name' => 'EU Zone', 'priority' => 0, 'status' => 'active']);
        // Inclusion: Country DE
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'DE', 'is_exclusion' => false]);
        // Exclusion: Postal 99999
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'postal_exact', 'country_code' => 'DE', 'postal_code_pattern' => '99999', 'is_exclusion' => true]);

        $context = new ShippingContext(tenantId: $this->tenant->id);

        // Allowed DE postal
        $destAllowed = new ShippingDestination(countryCode: 'DE', postalCode: '10115');
        $matchedAllowed = $this->matcher->match($destAllowed, $context);
        $this->assertCount(1, $matchedAllowed);

        // Excluded DE postal
        $destExcluded = new ShippingDestination(countryCode: 'DE', postalCode: '99999');
        $matchedExcluded = $this->matcher->match($destExcluded, $context);
        $this->assertEmpty($matchedExcluded);
    }

    public function test_leading_zero_and_alphanumeric_postal_codes(): void
    {
        $ukZone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'UK_LONDON', 'name' => 'London Zone', 'priority' => 0, 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $ukZone->id, 'rule_type' => 'postal_prefix', 'country_code' => 'GB', 'postal_code_pattern' => 'SW1A']);

        $context = new ShippingContext(tenantId: $this->tenant->id);
        $dest = new ShippingDestination(countryCode: 'GB', postalCode: 'SW1A 1AA');

        $matched = $this->matcher->match($dest, $context);
        $this->assertCount(1, $matched);
        $this->assertSame('UK_LONDON', $matched->first()?->code);
    }
}
