<?php

declare(strict_types=1);

namespace Modules\Cart\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Named *Log to avoid clashing with Modules\Cart\Notifications\AbandonedCartReminder.
 * Owner Delta correction §16: the (tenant_id, cart_id, reminder_sequence)
 * unique constraint on this table is the actual duplicate-send guard.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $cart_id
 * @property int $reminder_sequence
 * @property-read Cart $cart
 */
class AbandonedCartReminderLog extends Model
{
    use BelongsToTenant;

    protected $table = 'abandoned_cart_reminders';

    protected $fillable = [
        'tenant_id',
        'cart_id',
        'reminder_sequence',
        'sent_at',
    ];

    protected $casts = [
        'reminder_sequence' => 'integer',
        'sent_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }
}
