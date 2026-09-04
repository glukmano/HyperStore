<?php

declare(strict_types=1);

namespace Modules\Messaging\Models;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Marketplace\Models\Vendor;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property ?int $store_id
 * @property ?string $subject
 * @property ?string $context_type
 * @property ?int $context_id
 * @property ?int $vendor_id
 * @property string $status
 * @property ?Carbon $last_message_at
 */
class Conversation extends Model
{
    use BelongsToTenant;

    public const string STATUS_OPEN = 'open';

    public const string STATUS_CLOSED = 'closed';

    public const string STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'subject',
        'context_type',
        'context_id',
        'vendor_id',
        'status',
        'last_message_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $conversation): void {
            $conversation->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'store_id' => 'integer',
            'context_id' => 'integer',
            'vendor_id' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return HasMany<ConversationParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class, 'conversation_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('sent_at');
    }
}
