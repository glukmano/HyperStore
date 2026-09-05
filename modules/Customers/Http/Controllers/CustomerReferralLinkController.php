<?php

declare(strict_types=1);

namespace Modules\Customers\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\Customers\Services\CustomerReferralService;

/**
 * Phase-19 Final Completion Delta §3: the one thing a Customer referral
 * link/code must actually do when visited — mint the first-party
 * `hs_ref_code` cookie that CaptureReferralSignupListener reads at signup.
 * Deliberately the simplest possible capture: no click-tracking row, no
 * fraud detection — Customer referral is a peer-to-peer, non-monetary
 * bounded context, distinct from Affiliate's click-tracking pipeline
 * (ADR-0143).
 */
class CustomerReferralLinkController extends Controller
{
    public function visit(string $code): RedirectResponse
    {
        return redirect()->to('/')->withCookie(
            cookie(CustomerReferralService::COOKIE_NAME, strtoupper($code), 60 * 24 * 30, sameSite: 'lax')
        );
    }
}
