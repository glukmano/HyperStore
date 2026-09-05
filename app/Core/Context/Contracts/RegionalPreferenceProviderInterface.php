<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

/**
 * Core defines the contract; Modules\Customers implements it
 * (CustomerRegionalPreferenceProvider) — the same published-contract
 * pattern already used for SaveForLaterServiceInterface, so
 * App\Core\Context never reaches into a Module's own Eloquent models
 * directly (architecture-boundaries).
 */
interface RegionalPreferenceProviderInterface
{
    public function getPreferredLocale(int $userId): ?string;

    public function getPreferredCurrency(int $userId): ?string;
}
