<?php

declare(strict_types=1);

namespace Modules\Cart\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Cart\Models\Cart;
use Modules\Notifications\Contracts\HasNotificationChannels;

class AbandonedCartReminder extends Notification implements HasNotificationChannels, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Cart $cart,
        public readonly int $reminderSequence,
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
        return (new MailMessage)
            ->subject('You left something in your cart')
            ->line('You still have items waiting in your cart — come back and complete your order.')
            ->action('View Cart', url('/cart'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'cart_id' => $this->cart->id,
            'reminder_sequence' => $this->reminderSequence,
        ];
    }
}
