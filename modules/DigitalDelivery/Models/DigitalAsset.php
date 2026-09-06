<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Product;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $product_id
 * @property string $disk
 * @property string $path
 * @property int $version
 * @property ?string $checksum
 * @property ?int $max_downloads
 * @property ?int $download_expiry_days
 */
class DigitalAsset extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'disk',
        'path',
        'version',
        'checksum',
        'max_downloads',
        'download_expiry_days',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'max_downloads' => 'integer',
            'download_expiry_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DigitalAsset $asset): void {
            if (empty($asset->uuid)) {
                $asset->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
