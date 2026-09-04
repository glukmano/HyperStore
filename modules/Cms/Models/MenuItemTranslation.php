<?php

declare(strict_types=1);

namespace Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $menu_item_id
 * @property string $locale
 * @property string $label
 */
class MenuItemTranslation extends Model
{
    protected $fillable = ['menu_item_id', 'locale', 'label'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['menu_item_id' => 'integer'];
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
