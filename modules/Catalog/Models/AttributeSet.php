<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $code
 * @property string $status
 */
class AttributeSet extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<AttributeGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(AttributeGroup::class, 'attribute_set_id')->orderBy('sort_order');
    }

    /**
     * @return BelongsToMany<Attribute, $this>
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'attribute_set_attributes')
            ->withPivot('attribute_group_id', 'sort_order', 'is_required')
            ->withTimestamps();
    }
}
