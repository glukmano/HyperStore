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
use Modules\Catalog\Models\Product;
use Modules\Order\Models\OrderItem;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $product_id
 * @property int $user_id
 * @property ?int $order_item_id
 * @property int $rating
 * @property ?string $title
 * @property string $body
 * @property bool $is_verified_purchase
 * @property string $status
 * @property ?int $moderated_by_user_id
 * @property ?Carbon $moderated_at
 * @property ?string $moderation_reason
 * @property int $helpful_count
 */
class ProductReview extends Model implements HasMedia
{
    use BelongsToTenant, InteractsWithMedia;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_FLAGGED = 'flagged';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'user_id',
        'order_item_id',
        'rating',
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
            'product_id' => 'integer',
            'user_id' => 'integer',
            'order_item_id' => 'integer',
            'rating' => 'integer',
            'is_verified_purchase' => 'boolean',
            'moderated_by_user_id' => 'integer',
            'moderated_at' => 'datetime',
            'helpful_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return HasMany<ProductReviewReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ProductReviewReply::class, 'product_review_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('review_photos');
        $this->addMediaCollection('review_videos');
    }
}
