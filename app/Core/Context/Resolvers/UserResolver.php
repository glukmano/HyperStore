<?php

declare(strict_types=1);

namespace App\Core\Context\Resolvers;

use App\Core\Context\Contracts\UserContextInterface;
use App\Core\Context\Contracts\UserResolverInterface;
use App\Core\Context\DTOs\UserContext;
use App\Models\User;
use Illuminate\Http\Request;

class UserResolver implements UserResolverInterface
{
    public function __construct(
        private readonly ?Request $request = null,
    ) {}

    public function resolve(): UserContextInterface
    {
        $user = $this->request?->user();

        if ($user instanceof User) {
            return UserContext::from($user->id, $user->email);
        }

        return UserContext::guest();
    }
}
