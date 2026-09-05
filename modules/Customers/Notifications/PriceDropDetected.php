<?php

declare(strict_types=1);

namespace Modules\Customers\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Catalog\Models\Product;
use Modules\Notifications\Contracts\HasNotificationChannels;

/**
 * database + mail only — the smallest first-party notification boundary
 * (Phase-17 §10). No SMS/WhatsApp/push channel.
 */
class PriceDropDetected extends Notification implements HasNotificationChannels, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Product $product,
        public readonly int $newAmountMinor,
        public readonly string $currency,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formatted = number_format($this->newAmountMinor / 100, 2).' '.$this->currency;

        return (new MailMessage)
            ->subject('Price drop on an item you follow')
            ->line("The price of \"{$this->product->name}\" has dropped to {$formatted}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'product_id' => $this->product->id,
            'new_amount_minor' => $this->newAmountMinor,
            'currency' => $this->currency,
        ];
    }
}
