<?php

declare(strict_types=1);

namespace Modules\POS\Exceptions;

use RuntimeException;

/**
 * Pre-Production Readiness gate: a cash movement can never be recorded
 * against a RegisterSession that has already been closed — reconciled cash
 * must not move after the drawer is counted. The session row lock inside
 * PosCashMovementService::record() and PosRegisterSessionService::close()
 * is the shared serialization point that makes this exception impossible
 * to race past (proven under real PostgreSQL concurrency).
 */
class PosRegisterSessionClosedException extends RuntimeException {}
