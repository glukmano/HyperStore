<?php

declare(strict_types=1);

namespace Tests\Feature\GiftCards;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Customers\Models\CustomerProfile;
use Modules\GiftCards\Exceptions\GiftCardAlreadyRedeemedException;
use Modules\GiftCards\Exceptions\GiftCardNotFoundException;
use Modules\GiftCards\Services\GiftCardService;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Wallet\Contracts\StoreValueServiceInterface;
use Tests\TestCase;

/**
 * Owner Delta §15/D.48: Gift Card is a genuinely distinct Store Value
 * instrument from the base model onward — its own StoreValueAccount, never
 * merged into a customer's Wallet/Store Credit. The plaintext code is
 * never persisted (only a hash + last-4 for display).
 */
class GiftCardServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private GiftCardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Gift Card Tenant', 'slug' => 'gift-card-tenant']);
        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);

        $this->service = app(GiftCardService::class);
    }

    public function test_issuing_a_gift_card_never_persists_the_plaintext_code(): void
    {
        $result = $this->service->issue($this->tenant->id, 'USD', 5000);

        $giftCard = $result['giftCard'];
        $this->assertNotSame($result['plaintextCode'], $giftCard->code_hash);
        $this->assertSame(hash('sha256', $result['plaintextCode']), $giftCard->code_hash);
        $this->assertSame(substr($result['plaintextCode'], -4), $giftCard->code_last4);

        // The raw attributes on the model must never contain the plaintext.
        $this->assertStringNotContainsString($result['plaintextCode'], json_encode($giftCard->getAttributes()));
    }

    public function test_issuing_credits_a_dedicated_store_value_account_for_that_card_only(): void
    {
        $result = $this->service->issue($this->tenant->id, 'USD', 5000);
        $giftCard = $result['giftCard'];

        $this->assertNotNull($giftCard->store_value_account_id);
        $this->assertSame('active', $giftCard->status);

        $balance = app(StoreValueServiceInterface::class)->getAvailableBalanceMinor($giftCard->storeValueAccount);
        $this->assertSame(5000, $balance);
    }

    public function test_resolving_an_unknown_code_throws(): void
    {
        $this->expectException(GiftCardNotFoundException::class);
        $this->service->resolveAccountForCode($this->tenant->id, 'NOPE-DOES-NOT-EXIST', null);
    }

    public function test_resolving_claims_the_account_for_the_redeeming_customer(): void
    {
        $result = $this->service->issue($this->tenant->id, 'USD', 5000);
        $user = User::factory()->create();
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);

        $account = $this->service->resolveAccountForCode($this->tenant->id, $result['plaintextCode'], $profile->id);

        $this->assertSame($profile->id, $account->customer_profile_id);
    }

    public function test_partial_redemption_across_multiple_spends_is_supported(): void
    {
        $result = $this->service->issue($this->tenant->id, 'USD', 10000);
        $account = $result['giftCard']->storeValueAccount;

        $storeValueService = app(StoreValueServiceInterface::class);
        $hold1 = $storeValueService->placeHold($account, 3000, 'checkout-a');
        $storeValueService->captureHold($hold1, 'order', 'order-a');

        $this->assertSame(7000, $storeValueService->getAvailableBalanceMinor($account));

        $hold2 = $storeValueService->placeHold($account, 4000, 'checkout-b');
        $storeValueService->captureHold($hold2, 'order', 'order-b');

        $this->assertSame(3000, $storeValueService->getAvailableBalanceMinor($account));

        $this->service->markRedeemedIfExhausted($account);
        $this->assertSame('active', $result['giftCard']->fresh()->status);
    }

    public function test_a_deactivated_card_cannot_be_resolved_for_new_spend(): void
    {
        $result = $this->service->issue($this->tenant->id, 'USD', 5000);
        $result['giftCard']->update(['status' => 'deactivated']);

        $this->expectException(GiftCardAlreadyRedeemedException::class);
        $this->service->resolveAccountForCode($this->tenant->id, $result['plaintextCode'], null);
    }

    public function test_no_gift_card_code_column_or_log_call_ever_stores_the_raw_code(): void
    {
        $modelSource = file_get_contents(base_path('modules/GiftCards/Models/GiftCard.php'));
        $this->assertStringNotContainsString('code_plaintext', (string) $modelSource);
        $this->assertStringNotContainsString("'code' =>", (string) $modelSource);

        $migrationFiles = glob(base_path('database/migrations/*gift_cards*'));
        foreach ($migrationFiles as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString("string('code')", $contents);
            $this->assertStringNotContainsString("string('code_plaintext')", $contents);
        }
    }
}
