<?php

declare(strict_types=1);

namespace Modules\Cms\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $status
 * @property ?Carbon $published_at
 * @property string $template
 * @property ?int $created_by_user_id
 * @property ?int $updated_by_user_id
 */
class Page extends Model
{
    use BelongsToTenant, Searchable;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_PUBLISHED = 'published';

    public const string STATUS_ARCHIVED = 'archived';

    protected $fillable = ['tenant_id', 'status', 'published_at', 'template', 'created_by_user_id', 'updated_by_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'published_at' => 'datetime',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<PageTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(PageTranslation::class, 'page_id');
    }

    /**
     * @return HasMany<PageBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(PageBlock::class, 'page_id')->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function translation(?string $locale = null): ?PageTranslation
    {
        $locale = $locale ?? app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.fallback_locale', 'en'))
            ?? $this->translations->first();
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && ($this->published_at === null || $this->published_at->isPast());
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPublished();
    }

    public function searchableAs(): string
    {
        return 'cms_pages';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $document = [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
        ];

        foreach ($this->translations as $translation) {
            $document['title_'.$translation->locale] = $translation->title;
            $document['slug_'.$translation->locale] = $translation->slug;
        }

        return $document;
    }
}
