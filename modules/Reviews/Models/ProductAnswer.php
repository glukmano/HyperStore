<?php

declare(strict_types=1);

namespace Modules\Reviews\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $question_id
 * @property int $user_id
 * @property bool $is_vendor_answer
 * @property string $body
 * @property string $status
 * @property bool $is_accepted
 */
class ProductAnswer extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    protected $fillable = ['question_id', 'user_id', 'is_vendor_answer', 'body', 'status', 'is_accepted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'question_id' => 'integer',
            'user_id' => 'integer',
            'is_vendor_answer' => 'boolean',
            'is_accepted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ProductQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ProductQuestion::class, 'question_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
