<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Exceptions\ImpersonationRevokedException;
use App\Core\SuperAdmin\Exceptions\ImpersonationSessionTerminatedException;
use App\Core\SuperAdmin\Exceptions\NestedImpersonationForbiddenException;
use App\Core\SuperAdmin\Exceptions\SuperAdminImpersonationForbiddenException;
use App\Core\SuperAdmin\Models\ImpersonationEvent;
use App\Core\SuperAdmin\Models\ImpersonationSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ImpersonationService implements ImpersonationServiceInterface
{
    public function startSession(
        int $impersonatorUserId,
        int $targetUserId,
        ?int $tenantId,
        ?int $storeId,
        ?int $vendorId,
        string $reason,
        string $ipAddress,
        string $userAgent
    ): array {
        return DB::transaction(function () use (
            $impersonatorUserId,
            $targetUserId,
            $tenantId,
            $storeId,
            $vendorId,
            $reason,
            $ipAddress,
            $userAgent
        ): array {
            /** @var User $targetUser */
            $targetUser = User::findOrFail($targetUserId);
            if ($targetUser->isSuperAdmin()) {
                throw SuperAdminImpersonationForbiddenException::attempted($targetUserId);
            }

            // Check if impersonator is already in an active session (no nested impersonation)
            $existingActive = ImpersonationSession::where('impersonator_user_id', $impersonatorUserId)
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->exists();

            if ($existingActive) {
                throw NestedImpersonationForbiddenException::attempted();
            }

            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);

            /** @var ImpersonationSession $session */
            $session = ImpersonationSession::create([
                'impersonator_user_id' => $impersonatorUserId,
                'target_user_id' => $targetUserId,
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'vendor_id' => $vendorId,
                'status' => 'active',
                'token_hash' => $tokenHash,
                'reason' => $reason,
                'started_at' => CarbonImmutable::now(),
                'expires_at' => CarbonImmutable::now()->addMinutes(60), // Strictly capped at 60 minutes
            ]);

            ImpersonationEvent::create([
                'session_uuid' => $session->uuid,
                'event_type' => 'started',
                'actor_id' => $impersonatorUserId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => [
                    'target_user_id' => $targetUserId,
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'vendor_id' => $vendorId,
                    'reason' => $reason,
                ],
                'created_at' => CarbonImmutable::now(),
            ]);

            return [
                'session' => $session,
                'token' => $plainToken,
            ];
        });
    }

    public function authenticateToken(string $token): ImpersonationSession
    {
        $tokenHash = hash('sha256', $token);

        /** @var ?ImpersonationSession $session */
        $session = ImpersonationSession::where('token_hash', $tokenHash)->first();

        if ($session === null || ! $session->isActive()) {
            $sessionUuid = $session !== null ? $session->uuid : 'unknown';
            throw ImpersonationRevokedException::sessionRevoked($sessionUuid);
        }

        return $session;
    }

    public function revokeSession(string $sessionUuid, string $reason, ?int $actorId = null): ImpersonationSession
    {
        return $this->endSession($sessionUuid, 'revoked', $reason, $actorId);
    }

    public function terminateSession(string $sessionUuid, string $reason, ?int $actorId = null): ImpersonationSession
    {
        return $this->endSession($sessionUuid, 'terminated', $reason, $actorId);
    }

    private function endSession(string $sessionUuid, string $terminalStatus, string $reason, ?int $actorId = null): ImpersonationSession
    {
        return DB::transaction(function () use ($sessionUuid, $terminalStatus, $reason, $actorId): ImpersonationSession {
            /** @var ImpersonationSession $session */
            $session = ImpersonationSession::where('uuid', $sessionUuid)->lockForUpdate()->firstOrFail();

            if (! in_array($session->status, ['active'], true)) {
                throw ImpersonationSessionTerminatedException::alreadyTerminated($sessionUuid);
            }

            $session->status = $terminalStatus;
            $session->terminated_at = CarbonImmutable::now();
            $session->termination_reason = $reason;
            $session->save();

            ImpersonationEvent::create([
                'session_uuid' => $session->uuid,
                'event_type' => $terminalStatus,
                'actor_id' => $actorId ?? $session->impersonator_user_id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'System/ControlCenter',
                'metadata' => [
                    'reason' => $reason,
                    'terminated_at' => now()->toIso8601String(),
                ],
                'created_at' => CarbonImmutable::now(),
            ]);

            return $session;
        });
    }
}
