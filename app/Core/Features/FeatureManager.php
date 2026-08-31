<?php

declare(strict_types=1);

namespace App\Core\Features;

use App\Core\Features\Contracts\FeatureManagerInterface;
use Laravel\Pennant\Feature;

/**
 * FeatureManager: Thin wrapper around Laravel Pennant.
 *
 * Provides a stable contract interface so that code never depends directly
 * on Pennant's static facade — keeping the feature flag backend swappable.
 */
final class FeatureManager implements FeatureManagerInterface
{
    public function active(string $feature, mixed $scope = null): bool
    {
        if ($scope !== null) {
            return Feature::for($scope)->active($feature);
        }

        return Feature::active($feature);
    }

    public function activate(string $feature, mixed $scope = null): void
    {
        if ($scope !== null) {
            Feature::for($scope)->activate($feature);

            return;
        }

        Feature::activate($feature);
    }

    public function deactivate(string $feature, mixed $scope = null): void
    {
        if ($scope !== null) {
            Feature::for($scope)->deactivate($feature);

            return;
        }

        Feature::deactivate($feature);
    }

    public function forget(string $feature): void
    {
        Feature::forget($feature);
    }
}
