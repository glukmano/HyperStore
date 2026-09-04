<?php

declare(strict_types=1);

namespace Modules\Cms\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $from_path
 * @property string $to_path
 * @property int $status_code
 * @property ?string $locale
 * @property bool $is_active
 * @property bool $is_external
 */
class Redirect extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'from_path', 'to_path', 'status_code', 'locale', 'is_active', 'is_external'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'status_code' => 'integer',
            'is_active' => 'boolean',
            'is_external' => 'boolean',
        ];
    }
}
