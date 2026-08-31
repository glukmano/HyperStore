<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Pricing\Contracts\TaxCalculatorInterface;
use Modules\Pricing\DTOs\TaxContext;
use Modules\Pricing\Models\TaxClass;
use Modules\Pricing\Models\TaxRate;
use Modules\Pricing\Models\TaxZone;
use Modules\Pricing\ValueObjects\MoneyValue;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'tax-test-tenant'],
        ['name' => 'Tax Test Tenant', 'status' => 'active']
    );

    $this->standardClass = TaxClass::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Standard VAT',
        'code' => 'standard',
        'is_default' => true,
    ]);

    $this->euZone = TaxZone::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Germany Zone',
        'code' => 'de-zone',
        'country_code' => 'DE',
        'priority' => 10,
    ]);

    // 19% VAT for Germany
    TaxRate::create([
        'tenant_id' => $this->tenant->id,
        'tax_class_id' => $this->standardClass->id,
        'tax_zone_id' => $this->euZone->id,
        'name' => 'German 19% VAT',
        'rate_percentage' => '19.0000',
        'priority' => 0,
    ]);
});

test('TaxCalculator computes Tax-Exclusive prices correctly', function (): void {
    $calculator = app(TaxCalculatorInterface::class);

    // Price entered excluding tax: Net = 100.00 EUR (10000 minor)
    $netAmount = MoneyValue::fromMinor(10000, 'EUR');
    $context = new TaxContext(
        tenantId: $this->tenant->id,
        countryCode: 'DE',
        isTaxInclusive: false
    );

    $result = $calculator->calculate($netAmount, $this->standardClass->id, $context);

    // Net: 100.00, Tax: 19.00, Gross: 119.00
    expect($result->netAmount->getMinorAmount())->toBe(10000)
        ->and($result->taxAmount->getMinorAmount())->toBe(1900)
        ->and($result->grossAmount->getMinorAmount())->toBe(11900);
});

test('TaxCalculator computes Tax-Inclusive prices correctly', function (): void {
    $calculator = app(TaxCalculatorInterface::class);

    // Price entered including tax: Gross = 119.00 EUR (11900 minor)
    $grossAmount = MoneyValue::fromMinor(11900, 'EUR');
    $context = new TaxContext(
        tenantId: $this->tenant->id,
        countryCode: 'DE',
        isTaxInclusive: true
    );

    $result = $calculator->calculate($grossAmount, $this->standardClass->id, $context);

    // Gross: 119.00, Net: 100.00, Tax: 19.00
    expect($result->grossAmount->getMinorAmount())->toBe(11900)
        ->and($result->netAmount->getMinorAmount())->toBe(10000)
        ->and($result->taxAmount->getMinorAmount())->toBe(1900);
});
