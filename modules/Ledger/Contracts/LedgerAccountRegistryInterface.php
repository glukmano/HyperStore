<?php

declare(strict_types=1);

namespace Modules\Ledger\Contracts;

use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Models\LedgerAccount;

interface LedgerAccountRegistryInterface
{
    public function ensureRequiredSystemAccounts(int $tenantId): void;

    public function getAccountByRole(int $tenantId, SystemAccountRole|string $role): LedgerAccount;
}
