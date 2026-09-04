<?php

declare(strict_types=1);

namespace Modules\Reviews\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vendor-staff-only responses to a review (Phase-17 scope decision, §11/§13
 * of the approved plan) — never a peer customer-to-customer thread, which
 * keeps Reviews from becoming a second Messaging surface.
 *
 * @property int $id
 * @property int $product_review_id
 * @property int $user_id
 * @property string $body
 * @property string $status
 */
class ProductReviewReply extends Model
{
    protected $fillable = ['product_review_id', 'user_id', 'body', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_review_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'product_review_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
