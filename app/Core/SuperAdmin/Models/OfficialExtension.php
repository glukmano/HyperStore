<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property string $publisher_name
 * @property string $category
 * @property string $status
 * @property ?string $approved_version
 * @property array<string, mixed> $compatibility_metadata
 * @property string $visibility
 * @property ?CarbonImmutable $approved_at
 * @property ?CarbonImmutable $published_at
 */
class OfficialExtension extends Model
{
    protected $table = 'official_extensions';

    protected $fillable = [
        'uuid',
        'slug',
        'name',
        'publisher_name',
        'category',
        'status',
        'approved_version',
        'compatibility_metadata',
        'visibility',
        'approved_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'compatibility_metadata' => 'array',
            'approved_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
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

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'published'], true);
    }
}
