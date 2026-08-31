<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Traits;

use App\Core\Context\ContextManager;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if (app()->bound(ContextManager::class)) {
                $contextManager = app(ContextManager::class);
                if ($contextManager->hasTenant() && empty($model->tenant_id)) {
                    $model->tenant_id = $contextManager->getTenant()->getId();
                }
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
}
