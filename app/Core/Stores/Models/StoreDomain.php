<?php

declare(strict_types=1);

namespace App\Core\Stores\Models;

use App\Core\Routing\HostnameClaimService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $store_id
 * @property string $domain
 * @property string $type
 * @property bool $is_verified
 * @property bool $canonical
 */
class StoreDomain extends Model
{
    protected $table = 'store_domains';

    protected $fillable = [
        'store_id',
        'domain',
        'type',
        'is_verified',
        'canonical',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'is_verified' => 'boolean',
            'canonical' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            app(HostnameClaimService::class)->claim($model->domain, 'store', (int) $model->store_id);
        });

        static::deleted(function (self $model): void {
            app(HostnameClaimService::class)->release($model->domain);
        });
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
