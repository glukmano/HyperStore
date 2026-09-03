<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Exceptions\ImpersonationRevokedException;
use App\Core\SuperAdmin\Exceptions\ImpersonationSessionTerminatedException;
use App\Core\SuperAdmin\Exceptions\NestedImpersonationForbiddenException;
use App\Core\SuperAdmin\Exceptions\PrivilegedActionBlockedException;
use App\Core\SuperAdmin\Exceptions\SuperAdminImpersonationForbiddenException;
use App\Core\SuperAdmin\Models\ImpersonationEvent;
use App\Core\SuperAdmin\Models\ImpersonationSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ImpersonationService implements ImpersonationServiceInterface
{
    /**
     * Prohibited actions during impersonated sessions.
     */
    private const PROHIBITED_ACTIONS = [
        'password_change',
        'credential_mutation',
        'payout_finalization',
        'payout_approval',
    ];

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
            // 1. Lock the impersonator user row to serialize startSession requests from this actor
            User::where('id', $impersonatorUserId)->lockForUpdate()->findOrFail($impersonatorUserId);

            /** @var User $targetUser */
            $targetUser = User::findOrFail($targetUserId);
            if ($targetUser->isSuperAdmin()) {
                throw SuperAdminImpersonationForbiddenException::attempted($targetUserId);
            }

            // 2. Authoritatively expire any stale sessions for this impersonator
            $this->expireStaleSessionsForUser($impersonatorUserId, $ipAddress, $userAgent);

            // 3. Assert no active session remains (no nested impersonation)
            $existingActive = ImpersonationSession::where('impersonator_user_id', $impersonatorUserId)
                ->where('status', 'active')
                ->exists();

            if ($existingActive) {
                throw NestedImpersonationForbiddenException::attempted();
            }

            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);

            try {
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
            } catch (QueryException $e) {
                // Defense-in-depth against concurrent insert violating unique_active_impersonation_per_impersonator
                if (str_contains($e->getMessage(), 'unique_active_impersonation_per_impersonator') || $e->getCode() === '23505') {
                    throw NestedImpersonationForbiddenException::attempted();
                }
                throw $e;
            }
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

    public function executeAuthorized(string $token, string $action, callable $callback): mixed
    {
        $tokenHash = hash('sha256', $token);

        $result = DB::transaction(function () use ($tokenHash, $action, $callback): array {
            /** @var ?ImpersonationSession $session */
            $session = ImpersonationSession::where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return ['outcome' => 'revoked', 'uuid' => 'unknown'];
            }

            if ($session->status !== 'active') {
                return ['outcome' => 'revoked', 'uuid' => $session->uuid];
            }

            // Authoritative expiry check under lock
            if ($session->expires_at->isPast()) {
                $session->status = 'expired';
                $session->terminated_at = CarbonImmutable::now();
                $session->termination_reason = 'timeout';
                $session->save();

                ImpersonationEvent::create([
                    'session_uuid' => $session->uuid,
                    'event_type' => 'expired',
                    'actor_id' => $session->impersonator_user_id,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'System/ControlCenter',
                    'metadata' => [
                        'reason' => 'Session expired during authorization evaluation',
                        'expired_at' => CarbonImmutable::now()->toIso8601String(),
                    ],
                    'created_at' => CarbonImmutable::now(),
                ]);

                return ['outcome' => 'expired', 'uuid' => $session->uuid];
            }

            // Prohibited action check during impersonation
            if (in_array($action, self::PROHIBITED_ACTIONS, true)) {
                ImpersonationEvent::create([
                    'session_uuid' => $session->uuid,
                    'event_type' => 'privileged_action_blocked',
                    'actor_id' => $session->impersonator_user_id,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'System/ControlCenter',
                    'metadata' => [
                        'action' => $action,
                        'blocked_at' => CarbonImmutable::now()->toIso8601String(),
                    ],
                    'created_at' => CarbonImmutable::now(),
                ]);

                return ['outcome' => 'prohibited', 'action' => $action];
            }

            // Execute callback inside the locked critical section and return value
            return ['outcome' => 'success', 'data' => $callback($session)];
        });

        if ($result['outcome'] === 'revoked' || $result['outcome'] === 'expired') {
            throw ImpersonationRevokedException::sessionRevoked((string) $result['uuid']);
        }

        if ($result['outcome'] === 'prohibited') {
            throw PrivilegedActionBlockedException::blocked((string) $result['action']);
        }

        return $result['data'];
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

    private function expireStaleSessionsForUser(int $userId, string $ipAddress, string $userAgent): void
    {
        /** @var Collection<int, ImpersonationSession> $expiredSessions */
        $expiredSessions = ImpersonationSession::where('impersonator_user_id', $userId)
            ->where('status', 'active')
            ->where('expires_at', '<=', CarbonImmutable::now())
            ->lockForUpdate()
            ->get();

        foreach ($expiredSessions as $expSession) {
            $expSession->status = 'expired';
            $expSession->terminated_at = CarbonImmutable::now();
            $expSession->termination_reason = 'timeout';
            $expSession->save();

            ImpersonationEvent::create([
                'session_uuid' => $expSession->uuid,
                'event_type' => 'expired',
                'actor_id' => $userId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => [
                    'reason' => 'Authoritative expiry during new session negotiation',
                ],
                'created_at' => CarbonImmutable::now(),
            ]);
        }
    }
}
