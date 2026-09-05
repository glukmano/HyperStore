<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Customers\Services\CustomerReferralService;
use Tests\TestCase;

/**
 * Phase-19 Final Completion Delta §3: visiting a Customer's referral link
 * must actually mint the hs_ref_code cookie CaptureReferralSignupListener
 * reads at signup — before this controller existed, nothing in the
 * codebase ever set that cookie, so referral links were non-functional.
 */
class CustomerReferralLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_visiting_a_referral_link_sets_the_referral_cookie(): void
    {
        $response = $this->get('/refer/ABC12345');

        $response->assertRedirect('/');
        $response->assertCookie(CustomerReferralService::COOKIE_NAME, 'ABC12345');
    }
}
