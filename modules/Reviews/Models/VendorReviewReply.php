<?php

declare(strict_types=1);

namespace Modules\Reviews\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $vendor_review_id
 * @property int $user_id
 * @property string $body
 * @property string $status
 */
class VendorReviewReply extends Model
{
    protected $fillable = ['vendor_review_id', 'user_id', 'body', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vendor_review_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<VendorReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(VendorReview::class, 'vendor_review_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
