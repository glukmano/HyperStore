<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

use App\Core\SuperAdmin\Models\ImpersonationSession;

interface ImpersonationServiceInterface
{
    /**
     * @return array{session: ImpersonationSession, token: string}
     */
    public function startSession(
        int $impersonatorUserId,
        int $targetUserId,
        ?int $tenantId,
        ?int $storeId,
        ?int $vendorId,
        string $reason,
        string $ipAddress,
        string $userAgent
    ): array;

    public function authenticateToken(string $token): ImpersonationSession;

    public function revokeSession(string $sessionUuid, string $reason, ?int $actorId = null): ImpersonationSession;

    public function terminateSession(string $sessionUuid, string $reason, ?int $actorId = null): ImpersonationSession;
}
