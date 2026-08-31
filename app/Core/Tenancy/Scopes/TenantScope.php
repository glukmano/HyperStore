<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Scopes;

use App\Core\Context\ContextManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound(ContextManager::class)) {
            return;
        }

        $contextManager = app(ContextManager::class);

        if ($contextManager->hasTenant()) {
            $tenantId = $contextManager->getTenant()->getId();
            if ($tenantId !== null) {
                $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
            }
        }
    }
}
