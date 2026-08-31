<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $type
 * @property ?array<string, mixed> $validation_rules
 * @property bool $is_filterable
 * @property bool $is_searchable
 * @property bool $is_comparable
 * @property bool $is_variant_driving
 * @property bool $is_visible_on_front
 * @property int $sort_order
 * @property string $status
 */
class Attribute extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'type',
        'validation_rules',
        'is_filterable',
        'is_searchable',
        'is_comparable',
        'is_variant_driving',
        'is_visible_on_front',
        'sort_order',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'validation_rules' => 'array',
            'is_filterable' => 'boolean',
            'is_searchable' => 'boolean',
            'is_comparable' => 'boolean',
            'is_variant_driving' => 'boolean',
            'is_visible_on_front' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isSelectable(): bool
    {
        return in_array($this->type, ['select', 'multiselect', 'color'], true);
    }

    /**
     * @return HasMany<AttributeTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(AttributeTranslation::class, 'attribute_id');
    }

    /**
     * @return HasMany<AttributeOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class, 'attribute_id')->orderBy('sort_order');
    }

    public function translation(?string $locale = null): ?AttributeTranslation
    {
        $locale = $locale ?? app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.fallback_locale', 'en'))
            ?? $this->translations->first();
    }

    public function getNameAttribute(): string
    {
        $trans = $this->translation();

        return $trans !== null ? $trans->name : (string) $this->code;
    }
}
