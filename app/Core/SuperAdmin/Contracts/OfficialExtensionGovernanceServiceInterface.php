<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

use App\Core\SuperAdmin\Models\OfficialExtension;

interface OfficialExtensionGovernanceServiceInterface
{
    /**
     * @param  array<string, mixed>  $compatibility
     */
    public function registerExtension(
        string $slug,
        string $name,
        string $publisher,
        string $category,
        array $compatibility = []
    ): OfficialExtension;

    public function approveExtension(int $extensionId, string $approvedVersion): OfficialExtension;

    public function publishExtension(int $extensionId): OfficialExtension;

    public function suspendExtension(int $extensionId): OfficialExtension;
}
