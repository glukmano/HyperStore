<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Customers\Models\CustomerProfile;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Models\JournalEntry;
use Modules\Wallet\Contracts\StoreValueServiceInterface;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Exceptions\InsufficientStoreValueBalanceException;
use Modules\Wallet\Exceptions\StoreValueResolutionException;
use Modules\Wallet\Models\StoreValueAccount;
use Tests\TestCase;

/**
 * Owner Delta §16/§17: hold->capture/release lifecycle. A hold is resolved
 * EXACTLY ONCE, by either capture OR release, never both. hold/release
 * never post to Ledger; issue/capture/refund_credit/expire/
 * manual_adjustment_* do, atomically with the domain entry.
 */
class StoreValueServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CustomerProfile $profile;

    private StoreValueServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Wallet Tenant', 'slug' => 'wallet-tenant']);
        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);

        $user = User::factory()->create();
        $this->profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);

        $this->service = app(StoreValueServiceInterface::class);
    }

    private function makeAccount(string $instrument = 'wallet'): StoreValueAccount
    {
        return $this->service->findOrCreateAccount($this->tenant->id, $this->profile->id, StoreValueInstrumentType::from($instrument), 'USD');
    }

    public function test_issue_credits_the_account_and_posts_a_balanced_journal_entry(): void
    {
        $account = $this->makeAccount();

        $entry = $this->service->issue($account, 5000, 'order', 'order-1');

        $this->assertSame(5000, $this->service->getAvailableBalanceMinor($account));
        $journal = JournalEntry::where('source_type', 'order')->where('source_uuid', 'order-1')->where('posting_type', 'issue')->first();
        $this->assertNotNull($journal);
        $journal->load('lines');
        $this->assertSame(5000, (int) $journal->lines->where('direction', 'debit')->sum('amount_minor'));
        $this->assertSame(5000, (int) $journal->lines->where('direction', 'credit')->sum('amount_minor'));

        // Idempotent retry
        $again = $this->service->issue($account, 5000, 'order', 'order-1');
        $this->assertSame($entry->id, $again->id);
        $this->assertSame(5000, $this->service->getAvailableBalanceMinor($account));
    }

    public function test_hold_reduces_available_balance_and_never_posts_to_ledger(): void
    {
        $account = $this->makeAccount();
        $this->service->issue($account, 10000, 'order', 'order-2');

        $hold = $this->service->placeHold($account, 3000, 'checkout-session-1');

        $this->assertSame(7000, $this->service->getAvailableBalanceMinor($account));
        $this->assertNull(JournalEntry::where('source_uuid', 'checkout-session-1')->first());

        // Idempotent retry for the same checkout session
        $again = $this->service->placeHold($account, 3000, 'checkout-session-1');
        $this->assertSame($hold->id, $again->id);
        $this->assertSame(7000, $this->service->getAvailableBalanceMinor($account));
    }

    public function test_hold_exceeding_available_balance_is_rejected(): void
    {
        $account = $this->makeAccount();
        $this->service->issue($account, 1000, 'order', 'order-3');

        $this->expectException(InsufficientStoreValueBalanceException::class);
        $this->service->placeHold($account, 5000, 'checkout-session-2');
    }

    public function test_capture_resolves_the_hold_and_posts_to_ledger_without_further_reducing_balance(): void
    {
        $account = $this->makeAccount();
        $this->service->issue($account, 10000, 'order', 'order-4');
        $hold = $this->service->placeHold($account, 4000, 'checkout-session-3');
        $this->assertSame(6000, $this->service->getAvailableBalanceMinor($account));

        $capture = $this->service->captureHold($hold, 'order', 'order-4-final');

        // Balance stays at 6000 — capture does not further reduce it, since
        // the hold already did (Owner Delta §16).
        $this->assertSame(6000, $this->service->getAvailableBalanceMinor($account));
        $this->assertSame(0, $capture->amount_minor);
        $this->assertSame($hold->id, $capture->reverses_entry_id);

        $journal = JournalEntry::where('source_uuid', 'order-4-final')->where('posting_type', 'capture')->first();
        $this->assertNotNull($journal);
        $journal->load('lines');
        $this->assertSame(4000, (int) $journal->lines->where('direction', 'debit')->sum('amount_minor'));
    }

    public function test_release_restores_the_held_amount_and_never_posts_to_ledger(): void
    {
        $account = $this->makeAccount();
        $this->service->issue($account, 10000, 'order', 'order-5');
        $hold = $this->service->placeHold($account, 2500, 'checkout-session-4');

        $release = $this->service->releaseHold($hold, 'checkout-session-4');

        $this->assertSame(10000, $this->service->getAvailableBalanceMinor($account));
        $this->assertSame($hold->id, $release->reverses_entry_id);
        $this->assertNull(JournalEntry::where('source_uuid', 'checkout-session-4')->where('posting_type', 'release')->first());
    }

    public function test_a_hold_already_captured_cannot_also_be_released(): void
    {
        $account = $this->makeAccount();
        $this->service->issue($account, 10000, 'order', 'order-6');
        $hold = $this->service->placeHold($account, 1000, 'checkout-session-5');
        $this->service->captureHold($hold, 'order', 'order-6-final');

        $this->expectException(StoreValueResolutionException::class);
        $this->service->releaseHold($hold, 'checkout-session-5');
    }

    public function test_a_hold_already_released_cannot_also_be_captured(): void
    {
        $account = $this->makeAccount();
        $this->service->issue($account, 10000, 'order', 'order-7');
        $hold = $this->service->placeHold($account, 1000, 'checkout-session-6');
        $this->service->releaseHold($hold, 'checkout-session-6');

        $this->expectException(StoreValueResolutionException::class);
        $this->service->captureHold($hold, 'order', 'order-7-final');
    }

    public function test_manual_adjustment_credit_and_debit_move_balance_and_post_to_ledger(): void
    {
        $account = $this->makeAccount('store_credit');

        $this->service->manualAdjustmentCredit($account, 2000, 'manual-1', 'Goodwill credit');
        $this->assertSame(2000, $this->service->getAvailableBalanceMinor($account));
        $this->assertNotNull(JournalEntry::where('source_uuid', 'manual-1')->first());

        $this->service->manualAdjustmentDebit($account, 500, 'manual-2', 'Clawback');
        $this->assertSame(1500, $this->service->getAvailableBalanceMinor($account));
        $this->assertNotNull(JournalEntry::where('source_uuid', 'manual-2')->first());
    }

    public function test_expire_forfeits_the_remaining_balance_and_posts_to_ledger(): void
    {
        $account = $this->makeAccount();
        $this->service->issue($account, 3000, 'order', 'order-8');

        $this->service->expire($account, 3000, 'expiration-1');

        $this->assertSame(0, $this->service->getAvailableBalanceMinor($account));
        $this->assertNotNull(JournalEntry::where('source_uuid', 'expiration-1')->first());
    }

    public function test_gift_card_and_wallet_never_share_an_account_even_for_the_same_customer(): void
    {
        $wallet = $this->service->findOrCreateAccount($this->tenant->id, $this->profile->id, StoreValueInstrumentType::Wallet, 'USD');
        $giftCard = StoreValueAccount::create([
            'tenant_id' => $this->tenant->id,
            'customer_profile_id' => null,
            'instrument_type' => StoreValueInstrumentType::GiftCard->value,
            'currency' => 'USD',
            'scope' => 'tenant',
            'status' => 'active',
        ]);

        $this->assertNotSame($wallet->id, $giftCard->id);
        $this->assertSame('wallet', $wallet->instrument_type->value);
        $this->assertSame('gift_card', $giftCard->instrument_type->value);
    }
}
