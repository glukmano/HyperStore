<?php

declare(strict_types=1);

namespace App\Core\Localization\Enums;

/**
 * Phase-18 Owner Delta §2: direction is DB-driven (languages.direction),
 * never guessed from a hardcoded PHP list — the two drifted RTL-locale
 * arrays that previously lived here and in LocaleResolver are both gone.
 * This enum is now just the two possible values; resolving a Locale's
 * actual direction is LocaleManager::direction()'s job.
 */
enum Direction: string
{
    case LTR = 'ltr';
    case RTL = 'rtl';
}
