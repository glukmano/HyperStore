<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Customers\Models\CustomerProfile;
use Modules\Promotions\Exceptions\InsufficientLoyaltyPointsException;
use Modules\Promotions\Exceptions\NoLoyaltyCurrencyRuleException;
use Modules\Promotions\Models\LoyaltyProgram;
use Modules\Promotions\Models\LoyaltyProgramCurrencyRule;
use Modules\Promotions\Services\LoyaltyService;
use Tests\TestCase;

class LoyaltyServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CustomerProfile $profile;

    private LoyaltyProgram $program;

    private LoyaltyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Loyalty Tenant', 'slug' => 'loyalty-tenant']);
        $user = User::factory()->create();
        $this->profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);

        $this->program = LoyaltyProgram::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Default Loyalty',
            'pending_hold_days' => 0,
            'is_active' => true,
        ]);

        LoyaltyProgramCurrencyRule::create([
            'tenant_id' => $this->tenant->id,
            'loyalty_program_id' => $this->program->id,
            'currency' => 'EUR',
            'minor_units_per_point' => 100, // 1 point per 1.00 EUR
            'point_redemption_value_minor' => 5, // 1 point = 0.05 EUR
            'is_active' => true,
        ]);

        $this->service = app(LoyaltyService::class);
    }

    public function test_earn_points_is_immediately_available_with_no_hold_period(): void
    {
        $this->service->earnPoints($this->profile, 100, 'test', 'src-1');

        $this->assertSame(100, $this->service->getAvailableBalance($this->profile));
    }

    public function test_earn_points_is_idempotent_by_source(): void
    {
        $this->service->earnPoints($this->profile, 100, 'test', 'src-2');
        $this->service->earnPoints($this->profile, 100, 'test', 'src-2');

        $this->assertSame(100, $this->service->getAvailableBalance($this->profile));
    }

    public function test_earn_points_respects_pending_hold_period(): void
    {
        $this->program->pending_hold_days = 7;
        $this->program->save();

        $this->service->earnPoints($this->profile, 100, 'test', 'src-hold');
        $this->assertSame(0, $this->service->getAvailableBalance($this->profile));

        $matured = $this->service->maturePendingPoints($this->tenant->id, CarbonImmutable::now()->addDays(8));
        $this->assertSame(1, $matured);
        $this->assertSame(100, $this->service->getAvailableBalance($this->profile));
    }

    public function test_a_currency_with_no_rule_cannot_redeem(): void
    {
        $this->service->earnPoints($this->profile, 100, 'test', 'src-3');

        $this->expectException(NoLoyaltyCurrencyRuleException::class);
        $this->service->redeemPoints($this->profile, 10, 'USD', 'redeem-1');
    }

    public function test_redeeming_more_than_available_is_rejected(): void
    {
        $this->service->earnPoints($this->profile, 50, 'test', 'src-4');

        $this->expectException(InsufficientLoyaltyPointsException::class);
        $this->service->redeemPoints($this->profile, 100, 'EUR', 'redeem-2');
    }

    public function test_redemption_uses_the_currency_specific_rate_and_is_idempotent(): void
    {
        $this->service->earnPoints($this->profile, 100, 'test', 'src-5');

        $valueMinor = $this->service->redeemPoints($this->profile, 20, 'EUR', 'redeem-3');
        $this->assertSame(100, $valueMinor); // 20 points * 5 minor units

        $this->assertSame(80, $this->service->getAvailableBalance($this->profile));

        // Replaying the same redemption request returns the same frozen value, not a re-computation.
        $replayed = $this->service->redeemPoints($this->profile, 20, 'EUR', 'redeem-3');
        $this->assertSame(100, $replayed);
        $this->assertSame(80, $this->service->getAvailableBalance($this->profile));
    }

    public function test_client_cannot_forge_a_balance_it_is_always_recomputed_from_the_ledger(): void
    {
        $this->service->earnPoints($this->profile, 30, 'test', 'src-6');

        // Even if a caller tries to redeem far more than earned, the
        // authoritative ledger sum rejects it — no trusted client input.
        $this->expectException(InsufficientLoyaltyPointsException::class);
        $this->service->redeemPoints($this->profile, 999999, 'EUR', 'redeem-4');
    }

    public function test_expired_points_are_excluded_from_balance(): void
    {
        $this->program->points_expire_after_days = 30;
        $this->program->save();

        $this->service->earnPoints($this->profile, 100, 'test', 'src-7');
        $this->assertSame(100, $this->service->getAvailableBalance($this->profile));

        $expired = $this->service->expirePoints($this->tenant->id, CarbonImmutable::now()->addDays(31));
        $this->assertSame(1, $expired);
        $this->assertSame(0, $this->service->getAvailableBalance($this->profile));
    }
}
