<?php

declare(strict_types=1);

namespace Modules\Affiliate\Enums;

enum AffiliateFraudFlagType: string
{
    case SelfReferral = 'self_referral';
    case ClickVelocityAnomaly = 'click_velocity_anomaly';
    case ConversionVelocityAnomaly = 'conversion_velocity_anomaly';
    case DuplicateFingerprint = 'duplicate_fingerprint';
}
