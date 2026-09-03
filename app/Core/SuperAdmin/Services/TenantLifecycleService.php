<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\TenantLifecycleServiceInterface;
use App\Core\Tenancy\Enums\TenantOperationalStatus;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class TenantLifecycleService implements TenantLifecycleServiceInterface
{
    public function activate(int $tenantId): Tenant
    {
        return $this->transition($tenantId, TenantOperationalStatus::Active);
    }

    public function suspend(int $tenantId, string $reason): Tenant
    {
        return $this->transition($tenantId, TenantOperationalStatus::Suspended, $reason);
    }

    public function reactivate(int $tenantId): Tenant
    {
        return $this->transition($tenantId, TenantOperationalStatus::Active);
    }

    public function terminate(int $tenantId, string $reason): Tenant
    {
        return $this->transition($tenantId, TenantOperationalStatus::Terminated, $reason);
    }

    private function transition(int $tenantId, TenantOperationalStatus $target, ?string $reason = null): Tenant
    {
        return DB::transaction(function () use ($tenantId, $target, $reason): Tenant {
            /** @var Tenant $tenant */
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->findOrFail($tenantId);

            $current = $tenant->status instanceof TenantOperationalStatus
                ? $tenant->status
                : TenantOperationalStatus::from((string) $tenant->status);

            if (! $current->canTransitionTo($target)) {
                throw new InvalidArgumentException("Illegal tenant lifecycle transition from [{$current->value}] to [{$target->value}].");
            }

            $tenant->status = $target;
            if ($reason !== null) {
                $settings = $tenant->settings ?? [];
                $settings['last_status_reason'] = $reason;
                $settings['status_changed_at'] = now()->toIso8601String();
                $tenant->settings = $settings;
            }

            $tenant->save();

            return $tenant;
        });
    }
}
