<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Modules\Order\Exceptions\OrderAccessDeniedException;
use Modules\Order\Models\Order;

interface OrderOwnershipServiceInterface
{
    /**
     * Generates a new cryptographically secure guest order access token.
     * Returns the plaintext token.
     */
    public function generateGuestAccessToken(): string;

    /**
     * Computes the SHA-256 hash of a guest token for storage or lookup.
     */
    public function hashGuestToken(string $plainToken): string;

    /**
     * Verifies that the current request (authenticated user or guest token) has authorization
     * to access the specified order within the tenant.
     *
     * @throws OrderAccessDeniedException
     */
    public function verifyOwnership(Order $order, ?string $guestToken = null): void;
}
