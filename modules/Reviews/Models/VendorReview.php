<?php

declare(strict_types=1);

namespace Modules\Reviews\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Marketplace\Models\Vendor;
use Modules\Order\Models\Order;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_id
 * @property int $user_id
 * @property ?int $order_id
 * @property int $rating
 * @property ?int $communication_rating
 * @property ?int $shipping_rating
 * @property ?string $title
 * @property string $body
 * @property bool $is_verified_purchase
 * @property string $status
 * @property ?int $moderated_by_user_id
 * @property ?Carbon $moderated_at
 * @property ?string $moderation_reason
 * @property int $helpful_count
 */
class VendorReview extends Model
{
    use BelongsToTenant;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_FLAGGED = 'flagged';

    protected $fillable = [
        'tenant_id',
        'vendor_id',
        'user_id',
        'order_id',
        'rating',
        'communication_rating',
        'shipping_rating',
        'title',
        'body',
        'is_verified_purchase',
        'status',
        'moderated_by_user_id',
        'moderated_at',
        'moderation_reason',
        'helpful_count',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            $review->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'vendor_id' => 'integer',
            'user_id' => 'integer',
            'order_id' => 'integer',
            'rating' => 'integer',
            'communication_rating' => 'integer',
            'shipping_rating' => 'integer',
            'is_verified_purchase' => 'boolean',
            'moderated_by_user_id' => 'integer',
            'moderated_at' => 'datetime',
            'helpful_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return HasMany<VendorReviewReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(VendorReviewReply::class, 'vendor_review_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
