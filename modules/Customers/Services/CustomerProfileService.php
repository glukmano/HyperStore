<?php

declare(strict_types=1);

namespace Modules\Customers\Services;

use App\Core\Context\ContextManager;
use App\Models\User;
use Modules\Customers\Models\CustomerProfile;
use RuntimeException;

/**
 * Lazily creates a CustomerProfile on first storefront registration or first
 * engagement action — never via a global observer, since Control Center
 * staff/vendor-staff/super-admin logins must never receive one.
 */
final class CustomerProfileService
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    public function firstOrCreateFor(User $user): CustomerProfile
    {
        if (! $this->contextManager->hasTenant()) {
            throw new RuntimeException('Cannot create a CustomerProfile without a resolved tenant context.');
        }

        $tenantId = (int) $this->contextManager->getTenant()->getId();

        return CustomerProfile::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $user->id],
        );
    }
}
