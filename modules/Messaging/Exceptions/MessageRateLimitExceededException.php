<?php

declare(strict_types=1);

namespace Modules\Messaging\Exceptions;

use RuntimeException;

class MessageRateLimitExceededException extends RuntimeException {}
