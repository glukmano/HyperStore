<?php

declare(strict_types=1);

namespace Modules\Messaging\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property string $role
 * @property ?Carbon $last_read_at
 * @property bool $is_muted
 * @property Carbon $joined_at
 * @property ?Carbon $left_at
 */
class ConversationParticipant extends Model
{
    public const string ROLE_BUYER = 'buyer';

    public const string ROLE_VENDOR_STAFF = 'vendor_staff';

    public const string ROLE_TENANT_STAFF = 'tenant_staff';

    public $timestamps = false;

    protected $fillable = ['conversation_id', 'user_id', 'role', 'last_read_at', 'is_muted', 'joined_at', 'left_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'user_id' => 'integer',
            'last_read_at' => 'datetime',
            'is_muted' => 'boolean',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
