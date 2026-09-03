<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\ContextualMutationAuthorizerInterface;
use App\Core\SuperAdmin\Contracts\ControlCenterMutationExecutorInterface;
use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\SuperAdmin\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\Request;

final readonly class ControlCenterMutationExecutor implements ControlCenterMutationExecutorInterface
{
    public function __construct(
        private ImpersonationServiceInterface $impersonationService,
        private ContextualMutationAuthorizerInterface $authorizer
    ) {}

    public function execute(Request $request, string $action, callable $mutation): mixed
    {
        /** @var ?User $actor */
        $actor = $request->user();
        if ($actor === null) {
            throw UnauthorizedContextException::unauthenticated();
        }

        $impersonationToken = $request->header('X-Impersonation-Token');
        if ($impersonationToken !== null && is_string($impersonationToken) && trim($impersonationToken) !== '') {
            return $this->impersonationService->executeAuthorized(
                $impersonationToken,
                $action,
                function (ImpersonationSession $session) use ($actor, $mutation): mixed {
                    // 1. Verify authenticated actor matches impersonator in session
                    if ($actor->id !== $session->impersonator_user_id) {
                        throw UnauthorizedContextException::invalidContext('Authenticated user is not the authorized impersonator for this token.');
                    }

                    // 2. Verify target user exists and is active
                    /** @var ?User $targetUser */
                    $targetUser = User::find($session->target_user_id);
                    if ($targetUser === null || $targetUser->status !== 'active') {
                        throw UnauthorizedContextException::invalidContext('Impersonation target user is not valid or active.');
                    }

                    // 3. Execute mutation with effective user ID
                    return $mutation($session->target_user_id);
                }
            );
        }

        return $mutation($actor->id);
    }

    public function executeSuperAdmin(Request $request, callable $mutation): mixed
    {
        /** @var ?User $actor */
        $actor = $request->user();
        if ($actor === null) {
            throw UnauthorizedContextException::unauthenticated();
        }

        return $this->authorizer->executeSuperAdminAuthorized($actor->id, $mutation);
    }
}
