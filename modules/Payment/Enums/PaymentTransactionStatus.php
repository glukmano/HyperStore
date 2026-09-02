<?php

declare(strict_types=1);

namespace Modules\Payment\Enums;

enum PaymentTransactionStatus: string
{
    case PENDING = 'pending';
    case ACTION_REQUIRED = 'action_required';
    case SUCCESS = 'success';
    case FAILURE = 'failure';
    case UNKNOWN = 'unknown';
}
