<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\CarrierProviderInterface;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\Registries\CarrierRegistry;
use Modules\Shipping\ValueObjects\CarrierRateResult;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateOutcome;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use RuntimeException;
use Tests\TestCase;

class FailingCarrierProviderWithSecret implements CarrierProviderInterface
{
    public function calculateRates(Carrier $carrier, ShippingRateRequest $request): array
    {
        throw new RuntimeException('API Failed with SECRET_API_TOKEN_123 at endpoint https://api.carrier.com/v1/rates?token=SECRET_API_TOKEN_123');
    }
}

class WorkingCarrierProvider implements CarrierProviderInterface
{
    public function calculateRates(Carrier $carrier, ShippingRateRequest $request): array
    {
        return [
            new CarrierRateResult(
                carrierCode: $carrier->code,
                serviceCode: 'EXP_24',
                serviceName: 'Express 24h',
                rateAmount: MoneyValue::fromMinor(2200, $request->context->currency),
                transitDaysMin: 1,
                transitDaysMax: 1
            ),
        ];
    }
}

class CarrierTimeoutAndFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Timeout Tenant', 'slug' => 'timeout-tenant', 'status' => 'active']);
    }

    public function test_provider_exception_with_secret_token_is_redacted_and_never_leaks(): void
    {
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('failing_secret_provider', FailingCarrierProviderWithSecret::class, override: true);

        $loggedMessages = [];
        Log::listen(function ($log) use (&$loggedMessages) {
            $loggedMessages[] = $log->message.' '.json_encode($log->context);
        });

        $carrier = Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SECRET_FAIL_CARRIER',
            'name' => 'Secret Fail Carrier',
            'provider_code' => 'failing_secret_provider',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_SECRET_TEST', 'name' => 'CH Secret Test', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_SECRET_FAIL',
            'name' => 'Carrier Secret Fail',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'SECRET_FAIL_CARRIER', 'service_code' => 'STD'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);

        $engine = app(ShippingRateEngineInterface::class);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(5000, 'CHF'), 'unit_weight' => Weight::zero(), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ]
        );

        $result = $engine->calculateQuotes($request);

        // 1. Result outcome is PROVIDER_FAILURE
        $this->assertSame(ShippingRateOutcome::PROVIDER_FAILURE, $result->outcome);
        $this->assertTrue($result->quotes->isEmpty());

        // 2. Structured ProviderError contains safe sanitized message and never leaks SECRET_API_TOKEN_123
        $this->assertNotEmpty($result->errors);
        $error = $result->errors[0];
        $this->assertSame('provider_internal_error', $error->errorCode);
        $this->assertStringNotContainsString('SECRET_API_TOKEN_123', $error->safeMessage);
        $this->assertStringNotContainsString('https://', $error->safeMessage);

        // 3. Application logs NEVER contain the secret token
        foreach ($loggedMessages as $msg) {
            $this->assertStringNotContainsString('SECRET_API_TOKEN_123', $msg);
            $this->assertStringNotContainsString('api.carrier.com', $msg);
        }
    }

    public function test_provider_a_fails_provider_b_succeeds_and_static_succeeds(): void
    {
        /** @var CarrierRegistry $carrierRegistry */
        $carrierRegistry = app(CarrierRegistry::class);
        $carrierRegistry->register('failing_secret_provider', FailingCarrierProviderWithSecret::class, override: true);
        $carrierRegistry->register('working_provider_b', WorkingCarrierProvider::class, override: true);

        // Carrier A (failing)
        Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_A',
            'name' => 'Carrier A',
            'provider_code' => 'failing_secret_provider',
            'status' => 'active',
        ]);

        // Carrier B (working)
        Carrier::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'CARRIER_B',
            'name' => 'Carrier B',
            'provider_code' => 'working_provider_b',
            'status' => 'active',
        ]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_MULTI_TEST', 'name' => 'CH Multi', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);

        // Method A: Carrier A (Failing)
        $methodA = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'METHOD_CARRIER_A',
            'name' => 'Method Carrier A',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'priority' => 10,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'CARRIER_A', 'service_code' => 'STD'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $methodA->id, 'shipping_zone_id' => $zone->id]);

        // Method B: Carrier B (Working)
        $methodB = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'METHOD_CARRIER_B',
            'name' => 'Method Carrier B',
            'rate_calculator_type' => 'carrier_calculated',
            'currency' => 'CHF',
            'base_amount' => 0,
            'priority' => 20,
            'status' => 'active',
            'metadata' => ['carrier_code' => 'CARRIER_B', 'service_code' => 'EXP_24'],
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $methodB->id, 'shipping_zone_id' => $zone->id]);

        // Method C: Flat rate static
        $methodC = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'METHOD_STATIC',
            'name' => 'Method Static',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 900,
            'priority' => 5,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $methodC->id, 'shipping_zone_id' => $zone->id]);

        $engine = app(ShippingRateEngineInterface::class);

        $request = new ShippingRateRequest(
            context: new ShippingContext(tenantId: $this->tenant->id, currency: 'CHF'),
            destination: new ShippingDestination(countryCode: 'CH'),
            lines: [
                ['product_id' => 1, 'variant_id' => null, 'quantity' => 1, 'unit_price' => MoneyValue::fromMinor(5000, 'CHF'), 'unit_weight' => Weight::zero(), 'dimensions' => null, 'shipping_class_id' => null, 'is_shippable' => true, 'inventory_source_id' => null],
            ]
        );

        $result = $engine->calculateQuotes($request);

        // Overall outcome is SUCCESS because valid quotes were produced
        $this->assertSame(ShippingRateOutcome::SUCCESS, $result->outcome);
        $this->assertCount(2, $result->quotes);
        $methodCodes = $result->quotes->pluck('methodCode')->all();
        $this->assertContains('METHOD_CARRIER_B', $methodCodes);
        $this->assertContains('METHOD_STATIC', $methodCodes);
        $this->assertNotContains('METHOD_CARRIER_A', $methodCodes);
    }
}
