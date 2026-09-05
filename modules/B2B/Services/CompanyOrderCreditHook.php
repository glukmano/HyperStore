<?php

declare(strict_types=1);

namespace Modules\B2B\Services;

use Carbon\CarbonImmutable;
use Modules\B2B\Contracts\CompanyOrderCreditHookInterface;
use Modules\B2B\Enums\CompanyInvoiceStatus;
use Modules\B2B\Enums\CompanyStatus;
use Modules\B2B\Models\Company;
use Modules\B2B\Models\CompanyInvoice;
use Modules\B2B\Models\CompanyUser;
use Modules\B2B\Models\QuoteLine;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Enums\PaymentStatus;
use Modules\Order\Models\Order;

final class CompanyOrderCreditHook implements CompanyOrderCreditHookInterface
{
    public function __construct(
        private readonly CompanyCreditService $creditService,
    ) {}

    public function applyCompanyContextAndReserveCredit(Order $order): void
    {
        /** @var CompanyUser|null $companyUser */
        $companyUser = CompanyUser::where('tenant_id', $order->tenant_id)
            ->where('user_id', $order->user_id)
            ->where('is_active', true)
            ->first();

        if ($companyUser === null) {
            return;
        }

        /** @var Company|null $company */
        $company = Company::where('id', $companyUser->company_id)->first();
        if ($company === null || $company->status !== CompanyStatus::Active) {
            return;
        }

        $checkout = CheckoutSession::find($order->checkout_id);
        $cart = $checkout?->cart;

        $quoteId = null;
        if ($cart !== null) {
            $cart->loadMissing('lines');
            $quoteLineId = $cart->lines->pluck('quote_line_id')->filter()->first();
            if ($quoteLineId !== null) {
                $quoteId = QuoteLine::where('id', $quoteLineId)->value('quote_id');
            }
        }

        $order->company_id = max(0, (int) $company->id);
        $order->quote_id = $quoteId !== null ? max(0, (int) $quoteId) : null;

        $paymentTermsDays = $company->payment_terms_days;
        if ($paymentTermsDays !== null) {
            $order->payment_terms_days = max(0, $paymentTermsDays);

            // Owner Delta §2: reservation happens strictly inside THIS
            // Order-creation transaction; an insufficient-credit rejection
            // propagates and rolls back the entire Order — never caught
            // here.
            $this->creditService->reserveForOrder(
                $company,
                (int) $order->grand_total_minor,
                (string) $order->currency,
                (string) $order->uuid
            );

            $order->payment_status = PaymentStatus::INVOICED->value;

            $order->save();

            CompanyInvoice::create([
                'tenant_id' => $order->tenant_id,
                'company_id' => $company->id,
                'order_id' => $order->id,
                'amount_minor' => $order->grand_total_minor,
                'currency' => $order->currency,
                'due_at' => CarbonImmutable::now()->addDays($paymentTermsDays),
                'status' => CompanyInvoiceStatus::Open,
            ]);

            return;
        }

        $order->save();
    }
}
