<?php

declare(strict_types=1);

namespace Modules\Booking\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Booking\Models\BookingService;
use Modules\Booking\Models\BookingSlot;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;

class BookingWidget extends Component
{
    public int $productId;

    public ?int $selectedSlotId = null;

    public ?string $errorMessage = null;

    public function mount(int $productId): void
    {
        $this->productId = $productId;
    }

    private function service(): ?BookingService
    {
        return BookingService::where('product_id', $this->productId)->first();
    }

    public function bookSlot(CartServiceInterface $cartService): void
    {
        $this->errorMessage = null;

        $context = app(ContextManager::class);
        $tenantId = $context->getTenant()->getId();
        $storeId = $context->getStore()->getId();
        if ($tenantId === null || $storeId === null || $this->selectedSlotId === null) {
            return;
        }

        $cart = $cartService->getOrCreateActiveCart(new CartContext(
            tenantId: (int) $tenantId,
            storeId: (int) $storeId,
            marketId: (int) ($context->getMarket()->getId() ?? 0),
            channelId: (int) ($context->getChannel()->getId() ?? 0),
            currency: $context->getCurrency()->getCode() ?? 'USD',
            locale: app()->getLocale(),
            userId: is_int(auth()->id()) ? auth()->id() : null,
            guestToken: session()->getId(),
        ));

        try {
            $cartService->addLine($cart, new CartLineItemData(
                productId: $this->productId,
                variantId: null,
                quantity: CartQuantity::fromInt(1),
                metadata: ['booking_slot_id' => $this->selectedSlotId],
            ));
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        session()->flash('success', __('Added to cart — complete Checkout to confirm your booking.'));
    }

    public function render(): View
    {
        $service = $this->service();

        $slots = collect();
        if ($service !== null) {
            $resourceIds = $service->eligibleResources()->pluck('booking_resources.id');
            $slots = BookingSlot::whereIn('booking_resource_id', $resourceIds)
                ->where('booking_service_id', $service->id)
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->limit(30)
                ->get()
                ->filter(fn (BookingSlot $slot) => $slot->usedCapacity() < $slot->capacity);
        }

        return view('booking::livewire.storefront.booking-widget', [
            'service' => $service,
            'slots' => $slots,
        ]);
    }
}
