<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

interface PlatformHealthServiceInterface
{
    /**
     * @return array{status: string, timestamp: string, checks: array<string, array{status: string, message: ?string}>}
     */
    public function checkHealth(): array;
}
