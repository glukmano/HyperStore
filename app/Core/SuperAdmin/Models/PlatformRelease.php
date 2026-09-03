<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $version
 * @property string $channel
 * @property string $status
 * @property string $release_notes
 * @property array<string, mixed> $compatibility_metadata
 * @property ?CarbonImmutable $published_at
 * @property ?CarbonImmutable $withdrawn_at
 */
class PlatformRelease extends Model
{
    protected $table = 'platform_releases';

    protected $fillable = [
        'uuid',
        'version',
        'channel',
        'status',
        'release_notes',
        'compatibility_metadata',
        'published_at',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'compatibility_metadata' => 'array',
            'published_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
