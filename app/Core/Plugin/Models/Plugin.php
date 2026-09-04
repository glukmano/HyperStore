<?php

declare(strict_types=1);

namespace App\Core\Plugin\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-level plugin lifecycle record (Owner Delta 2026-09-04: platform-level
 * only, no tenant/store scoping — see ADR-0133).
 *
 * @property int $id
 * @property string $plugin_id
 * @property string $name
 * @property string $version
 * @property string $status
 * @property string $trust_level
 * @property array<string, mixed> $manifest_snapshot
 * @property ?array<string, mixed> $granted_permissions
 * @property ?Carbon $permissions_approved_at
 * @property int $consecutive_boot_failures
 * @property ?int $last_migration_batch
 * @property ?string $failure_reason
 * @property ?Carbon $installed_at
 * @property ?Carbon $enabled_at
 * @property ?Carbon $disabled_at
 */
class Plugin extends Model
{
    public const string STATUS_DISCOVERED = 'discovered';

    public const string STATUS_INSTALLED = 'installed';

    public const string STATUS_ENABLED = 'enabled';

    public const string STATUS_DISABLED = 'disabled';

    public const string STATUS_UPDATE_AVAILABLE = 'update_available';

    public const string STATUS_INCOMPATIBLE = 'incompatible';

    public const string STATUS_FAILED = 'failed';

    public const string TRUST_OFFICIAL = 'official';

    public const string TRUST_VERIFIED_THIRD_PARTY = 'verified_third_party';

    public const string TRUST_UNVERIFIED = 'unverified';

    protected $table = 'plugins';

    protected $fillable = [
        'plugin_id',
        'name',
        'version',
        'status',
        'trust_level',
        'manifest_snapshot',
        'granted_permissions',
        'permissions_approved_at',
        'consecutive_boot_failures',
        'last_migration_batch',
        'failure_reason',
        'installed_at',
        'enabled_at',
        'disabled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manifest_snapshot' => 'array',
            'granted_permissions' => 'array',
            'permissions_approved_at' => 'datetime',
            'consecutive_boot_failures' => 'integer',
            'last_migration_batch' => 'integer',
            'installed_at' => 'datetime',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
