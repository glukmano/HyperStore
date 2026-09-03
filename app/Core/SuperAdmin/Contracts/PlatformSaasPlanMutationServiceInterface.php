<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

use App\Core\SuperAdmin\Models\PlatformSaasPlan;

interface PlatformSaasPlanMutationServiceInterface
{
    /**
     * @param  array<string, int>  $limits
     */
    public function updateHardLimits(int $planId, array $limits): PlatformSaasPlan;

    /**
     * @param  array<string, mixed>  $features
     */
    public function updateFeatureEntitlements(int $planId, array $features): PlatformSaasPlan;
}
