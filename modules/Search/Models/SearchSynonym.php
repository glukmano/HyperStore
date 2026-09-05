<?php

declare(strict_types=1);

namespace Modules\Search\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $locale
 * @property string $term
 * @property array<int, string> $synonyms
 */
class SearchSynonym extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'locale', 'term', 'synonyms'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'synonyms' => 'array'];
    }
}
