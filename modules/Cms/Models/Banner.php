<?php

declare(strict_types=1);

namespace Modules\Cms\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $placement
 * @property bool $is_active
 * @property int $sort_order
 * @property ?Carbon $starts_at
 * @property ?Carbon $ends_at
 */
class Banner extends Model implements HasMedia
{
    use BelongsToTenant, InteractsWithMedia;

    protected $fillable = ['tenant_id', 'placement', 'is_active', 'sort_order', 'starts_at', 'ends_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BannerTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(BannerTranslation::class, 'banner_id');
    }

    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        return ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile();
    }
}
