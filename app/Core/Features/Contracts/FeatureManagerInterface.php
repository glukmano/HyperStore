<?php

declare(strict_types=1);

namespace App\Core\Features\Contracts;

interface FeatureManagerInterface
{
    /**
     * Check if a feature flag is active for the given scope.
     *
     * @param  string  $feature  The feature name
     * @param  mixed  $scope  Scope (User, Tenant, null for global)
     */
    public function active(string $feature, mixed $scope = null): bool;

    /**
     * Activate a feature for the given scope.
     */
    public function activate(string $feature, mixed $scope = null): void;

    /**
     * Deactivate a feature for the given scope.
     */
    public function deactivate(string $feature, mixed $scope = null): void;

    /**
     * Forget all cached results for a feature.
     */
    public function forget(string $feature): void;
}
