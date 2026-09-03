<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

use App\Core\SuperAdmin\Models\PlatformRelease;

interface PlatformReleaseServiceInterface
{
    /**
     * @param  array<string, mixed>  $compatibility
     */
    public function createRelease(string $version, string $channel, string $notes, array $compatibility = []): PlatformRelease;

    public function publishRelease(int $releaseId): PlatformRelease;

    public function withdrawRelease(int $releaseId): PlatformRelease;
}
