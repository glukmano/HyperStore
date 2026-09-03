<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $session_uuid
 * @property string $event_type
 * @property int $actor_id
 * @property string $ip_address
 * @property string $user_agent
 * @property array<string, mixed> $metadata
 * @property CarbonImmutable $created_at
 * @property-read User $actor
 */
class ImpersonationEvent extends Model
{
    public $timestamps = false;

    protected $table = 'impersonation_events';

    protected $fillable = [
        'session_uuid',
        'event_type',
        'actor_id',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
