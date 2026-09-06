<?php

declare(strict_types=1);

namespace Modules\POS\Exceptions;

use RuntimeException;

/**
 * Owner Delta §2: thrown WITHOUT being caught by OrderCreationService — a
 * closed/invalid RegisterSession, an unauthorized cashier, or a Store/
 * Market mismatch must roll back the entire Order-creation transaction.
 */
class PosOrderContextException extends RuntimeException {}
