<?php

declare(strict_types=1);

namespace Modules\Payment\Enums;

enum ReconciliationStatus: string
{
    case SUCCESS = 'success';
    case FAILURE = 'failure';
    case ACTION_REQUIRED = 'action_required';
    case STILL_PENDING = 'still_pending';
    case UNKNOWN = 'unknown';
}
