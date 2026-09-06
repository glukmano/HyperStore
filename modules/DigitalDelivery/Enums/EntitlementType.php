<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Enums;

/**
 * Owner Delta D.36: one shared, explicitly-modeled entitlement type set —
 * both Digital and Subscription access-control questions reduce to the
 * same three checks (granted, non-revoked, non-expired/non-exhausted).
 */
enum EntitlementType: string
{
    case DigitalDownload = 'digital_download';
    case SubscriptionAccess = 'subscription_access';
}
