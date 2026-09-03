<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Marketplace\Models\Vendor;

/**
 * @property int $id
 * @property string $uuid
 * @property string $scope_type
 * @property int|null $tenant_id
 * @property int|null $vendor_id
 * @property string $code
 * @property string $name
 * @property string $contact_email
 * @property string|null $contact_phone
 * @property string $status
 * @property string $currency
 * @property bool $is_dropship_capable
 * @property int $lead_time_days
 * @property int $min_order_value_minor
 * @property int $rating_score
 * @property array<string, mixed>|null $settings
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Vendor|null $vendor
 * @property-read Collection<int, TenantSupplierAccess> $tenantAccesses
 * @property-read Collection<int, SupplierLocation> $locations
 * @property-read Collection<int, SupplierAccount> $accounts
 * @property-read Collection<int, SupplierProductVariant> $productVariants
 * @property-read Collection<int, SupplierOffer> $offers
 * @property-read Collection<int, PurchaseOrder> $purchaseOrders
 */
class Supplier extends Model
{
    use BelongsToTenant;

    protected $table = 'suppliers';

    protected $fillable = [
        'uuid',
        'scope_type',
        'tenant_id',
        'vendor_id',
        'code',
        'name',
        'contact_email',
        'contact_phone',
        'status',
        'currency',
        'is_dropship_capable',
        'lead_time_days',
        'min_order_value_minor',
        'rating_score',
        'settings',
    ];

    protected $casts = [
        'is_dropship_capable' => 'boolean',
        'lead_time_days' => 'integer',
        'min_order_value_minor' => 'integer',
        'rating_score' => 'integer',
        'settings' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->contact_email)) {
                $model->contact_email = 'supplier-'.($model->code ?? 'default').'@example.com';
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return HasMany<TenantSupplierAccess, $this>
     */
    public function tenantAccesses(): HasMany
    {
        return $this->hasMany(TenantSupplierAccess::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(SupplierLocation::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(SupplierAccount::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierProductVariant, $this>
     */
    public function productVariants(): HasMany
    {
        return $this->hasMany(SupplierProductVariant::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class, 'supplier_id');
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    public function getScopeAttribute(): string
    {
        return (string) $this->scope_type;
    }

    public function setScopeAttribute(string $value): void
    {
        $this->attributes['scope_type'] = $value;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function setIsActiveAttribute(bool $value): void
    {
        $this->attributes['status'] = $value ? 'active' : 'inactive';
    }

    public function isPlatform(): bool
    {
        return $this->scope_type === 'platform';
    }

    public function isTenant(): bool
    {
        return $this->scope_type === 'tenant';
    }

    public function isPrivateVendor(): bool
    {
        return $this->scope_type === 'private_vendor';
    }
}
