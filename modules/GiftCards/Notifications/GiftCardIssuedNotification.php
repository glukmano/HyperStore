<?php

declare(strict_types=1);

namespace Modules\GiftCards\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * D.37/D.48: the plaintext code is shown/emailed to the purchaser ONCE, at
 * issuance time — it is never persisted anywhere after this send (only its
 * hash lives in the gift_cards table).
 */
class GiftCardIssuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $plaintextCode,
        private readonly string $currency,
        private readonly int $valueMinor
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formattedValue = number_format($this->valueMinor / 100, 2).' '.$this->currency;

        return (new MailMessage)
            ->subject('Your Gift Card')
            ->line("You have received a Gift Card worth {$formattedValue}.")
            ->line("Code: {$this->plaintextCode}")
            ->line('Keep this code safe — it will not be shown again.');
    }
}
