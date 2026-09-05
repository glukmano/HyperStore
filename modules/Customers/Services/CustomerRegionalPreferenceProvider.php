<?php

declare(strict_types=1);

namespace Modules\Customers\Services;

use App\Core\Context\Contracts\RegionalPreferenceProviderInterface;
use Modules\Customers\Models\CustomerProfile;

final class CustomerRegionalPreferenceProvider implements RegionalPreferenceProviderInterface
{
    public function getPreferredLocale(int $userId): ?string
    {
        $value = CustomerProfile::query()->where('user_id', $userId)->value('preferred_locale');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getPreferredCurrency(int $userId): ?string
    {
        $value = CustomerProfile::query()->where('user_id', $userId)->value('preferred_currency');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
