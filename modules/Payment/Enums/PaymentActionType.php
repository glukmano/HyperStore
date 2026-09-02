<?php

declare(strict_types=1);

namespace Modules\Payment\Enums;

enum PaymentActionType: string
{
    case REDIRECT_URL = 'redirect_url';
    case CLIENT_SECRET = 'client_secret';
    case QR_CODE = 'qr_code';
}
