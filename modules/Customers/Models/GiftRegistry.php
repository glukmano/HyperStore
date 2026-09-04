<?php

declare(strict_types=1);

namespace Modules\Customers\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $title
 * @property string $event_type
 * @property ?string $event_date
 * @property string $visibility
 * @property string $share_token
 * @property ?array<string, mixed> $shipping_address
 * @property ?string $message
 */
class GiftRegistry extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'event_type',
        'event_date',
        'visibility',
        'share_token',
        'shipping_address',
        'message',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $registry): void {
            $registry->uuid ??= (string) Str::uuid();
            $registry->share_token ??= Str::random(48);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'event_date' => 'date',
            'shipping_address' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<GiftRegistryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(GiftRegistryItem::class, 'registry_id');
    }

    public function isPubliclyVisible(): bool
    {
        return in_array($this->visibility, ['unlisted', 'public'], true);
    }
}
