<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Database\Seeders\ReferenceDataSeeder;
use DateTimeZone;
use Modules\Order\Contracts\BusinessTimezoneResolverInterface;
use Modules\Order\Contracts\OrderNumberGeneratorInterface;
use Modules\Order\Exceptions\InvalidBusinessTimezoneException;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['name' => 'TZ Tenant', 'slug' => 'tz-tenant', 'status' => 'active']);
    $this->timezoneResolver = app(BusinessTimezoneResolverInterface::class);
    $this->numberGenerator = app(OrderNumberGeneratorInterface::class);
});

// ---------------------------------------------------------------------------
// 1. Timezone precedence: Market timezone first, then Store settings
// ---------------------------------------------------------------------------
test('timezone resolver uses market timezone first when present', function (): void {
    $market = Market::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Market Zurich',
        'code' => 'MKT-ZUR',
        'is_active' => true,
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'en',
        'timezone' => 'Europe/Zurich',
    ]);
    $store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Store Tokyo',
        'slug' => 'store-tokyo',
        'status' => 'active',
        'settings' => ['timezone' => 'Asia/Tokyo'],
    ]);

    $resolved = $this->timezoneResolver->resolve($market->id, $store->id);
    expect($resolved->getName())->toBe('Europe/Zurich');
});

test('timezone resolver falls back to store settings timezone if market timezone is empty', function (): void {
    $market = Market::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Market Empty TZ',
        'code' => 'MKT-EMPTY',
        'is_active' => true,
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'en',
        'timezone' => '',
    ]);
    $store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Store Tokyo',
        'slug' => 'store-tokyo-2',
        'status' => 'active',
        'settings' => ['timezone' => 'Asia/Tokyo'],
    ]);

    $resolved = $this->timezoneResolver->resolve($market->id, $store->id);
    expect($resolved->getName())->toBe('Asia/Tokyo');
});

// ---------------------------------------------------------------------------
// 2. Missing or invalid timezone fails closed
// ---------------------------------------------------------------------------
test('unresolvable timezone throws InvalidBusinessTimezoneException', function (): void {
    $market = Market::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Market None',
        'code' => 'MKT-NONE',
        'is_active' => true,
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'en',
        'timezone' => '',
    ]);
    $store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Store None',
        'slug' => 'store-none',
        'status' => 'active',
        'settings' => null,
    ]);

    expect(fn () => $this->timezoneResolver->resolve($market->id, $store->id))
        ->toThrow(InvalidBusinessTimezoneException::class);
});

test('invalid timezone string throws InvalidBusinessTimezoneException', function (): void {
    $market = Market::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Market Bad TZ',
        'code' => 'MKT-BAD',
        'is_active' => true,
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'en',
        'timezone' => 'Invalid/NonExistentZone',
    ]);
    $store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Store',
        'slug' => 'store-bad',
        'status' => 'active',
    ]);

    expect(fn () => $this->timezoneResolver->resolve($market->id, $store->id))
        ->toThrow(InvalidBusinessTimezoneException::class);
});

// ---------------------------------------------------------------------------
// 3. Day boundary test across positive and negative offsets
// ---------------------------------------------------------------------------
test('order number date matches business timezone at day boundary', function (): void {
    // Instant: 2026-09-02 23:30:00 UTC
    Carbon::setTestNow(Carbon::parse('2026-09-02 23:30:00', 'UTC'));

    // UTC: date 20260902
    $numUtc = $this->numberGenerator->generate($this->tenant->id, new DateTimeZone('UTC'));
    expect($numUtc)->toStartWith('ORD-20260902-');

    // Tokyo (+9h -> 2026-09-03 08:30:00): date 20260903
    $numTokyo = $this->numberGenerator->generate($this->tenant->id, new DateTimeZone('Asia/Tokyo'));
    expect($numTokyo)->toStartWith('ORD-20260903-');

    // Los Angeles (-7h -> 2026-09-02 16:30:00): date 20260902
    $numLA = $this->numberGenerator->generate($this->tenant->id, new DateTimeZone('America/Los_Angeles'));
    expect($numLA)->toStartWith('ORD-20260902-');

    // Morning boundary instant: 2026-09-02 02:00:00 UTC
    Carbon::setTestNow(Carbon::parse('2026-09-02 02:00:00', 'UTC'));

    // Los Angeles (-7h -> 2026-09-01 19:00:00): date 20260901
    $numLAEarly = $this->numberGenerator->generate($this->tenant->id, new DateTimeZone('America/Los_Angeles'));
    expect($numLAEarly)->toStartWith('ORD-20260901-');

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 4. Sequential monotonic numbering per tenant and date
// ---------------------------------------------------------------------------
test('order number counter increments monotonically and formats with zero padding', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00', 'UTC'));
    $tz = new DateTimeZone('UTC');

    $num1 = $this->numberGenerator->generate($this->tenant->id, $tz);
    $num2 = $this->numberGenerator->generate($this->tenant->id, $tz);
    $num3 = $this->numberGenerator->generate($this->tenant->id, $tz);

    expect($num1)->toBe('ORD-20260902-000001')
        ->and($num2)->toBe('ORD-20260902-000002')
        ->and($num3)->toBe('ORD-20260902-000003');

    Carbon::setTestNow();
});
