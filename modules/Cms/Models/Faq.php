<?php

declare(strict_types=1);

namespace Modules\Cms\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property ?string $category
 * @property int $sort_order
 * @property bool $is_published
 */
class Faq extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'category', 'sort_order', 'is_published'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'sort_order' => 'integer', 'is_published' => 'boolean'];
    }

    /**
     * @return HasMany<FaqTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(FaqTranslation::class, 'faq_id');
    }

    public function translation(?string $locale = null): ?FaqTranslation
    {
        $locale = $locale ?? app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.fallback_locale', 'en'))
            ?? $this->translations->first();
    }
}
