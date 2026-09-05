<?php

declare(strict_types=1);

namespace Modules\B2B\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\B2B\Enums\QuoteStatus;
use Modules\B2B\Exceptions\B2BException;
use Modules\B2B\Models\Company;
use Modules\B2B\Models\CompanyUser;
use Modules\B2B\Models\Quote;
use Modules\B2B\Services\QuoteService;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Catalog\Models\Product;

class QuoteRequestPage extends Component
{
    public string $productSku = '';

    public string $quantity = '1';

    public ?string $errorMessage = null;

    private function companyUser(): ?CompanyUser
    {
        /** @var User $user */
        $user = auth()->user();

        return CompanyUser::where('user_id', $user->id)->where('is_active', true)->first();
    }

    public function submitRfq(QuoteService $quoteService): void
    {
        $this->errorMessage = null;
        $companyUser = $this->companyUser();
        if ($companyUser === null) {
            $this->errorMessage = __('You are not a member of a Company account.');

            return;
        }

        $product = Product::where('tenant_id', $companyUser->tenant_id)->where('sku', $this->productSku)->first();
        if ($product === null) {
            $this->errorMessage = __('Product SKU not found.');

            return;
        }

        $company = Company::findOrFail($companyUser->company_id);

        /** @var User $user */
        $user = auth()->user();

        try {
            $quoteService->submitRfq(
                $company,
                $user,
                app(ContextManager::class)->getCurrency()->getCode() ?? 'USD',
                [['product_id' => $product->id, 'variant_id' => null, 'quantity' => $this->quantity]]
            );
        } catch (B2BException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->reset(['productSku', 'quantity']);
        session()->flash('success', __('Quote request submitted.'));
    }

    public function acceptQuote(int $quoteId, QuoteService $quoteService): void
    {
        $this->errorMessage = null;
        $quote = Quote::findOrFail($quoteId);

        $context = app(ContextManager::class);
        $tenantId = $context->getTenant()->getId();
        $storeId = $context->getStore()->getId();
        if ($tenantId === null || $storeId === null) {
            $this->errorMessage = __('A store context is required.');

            return;
        }

        /** @var User $user */
        $user = auth()->user();

        try {
            $quoteService->acceptAndBuildCart($quote, $user, new CartContext(
                tenantId: (int) $tenantId,
                storeId: (int) $storeId,
                marketId: (int) ($context->getMarket()->getId() ?? 0),
                channelId: (int) ($context->getChannel()->getId() ?? 0),
                currency: $quote->currency,
                locale: app()->getLocale(),
                userId: (int) $user->id,
                guestToken: session()->getId(),
            ));
        } catch (B2BException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->redirect(route('storefront.cart'), navigate: true);
    }

    public function render(): View
    {
        $companyUser = $this->companyUser();

        $quotes = $companyUser !== null
            ? Quote::where('company_id', $companyUser->company_id)
                ->whereIn('status', [QuoteStatus::Submitted, QuoteStatus::Quoted, QuoteStatus::Accepted, QuoteStatus::Rejected, QuoteStatus::Expired])
                ->with('lines')
                ->orderByDesc('id')
                ->get()
            : collect();

        return view('b2b::livewire.storefront.quote-request-page', [
            'quotes' => $quotes,
            'companyUser' => $companyUser,
        ])->layout('theme::layouts.app', ['title' => __('Quotes')]);
    }
}
