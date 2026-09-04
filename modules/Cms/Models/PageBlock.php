<?php

declare(strict_types=1);

namespace Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property string $block_type
 * @property int $position
 * @property ?array<string, mixed> $config
 * @property bool $is_visible
 */
class PageBlock extends Model
{
    protected $fillable = ['page_id', 'block_type', 'position', 'config', 'is_visible'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_id' => 'integer',
            'position' => 'integer',
            'config' => 'array',
            'is_visible' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }
}
