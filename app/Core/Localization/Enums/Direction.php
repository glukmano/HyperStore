<?php

declare(strict_types=1);

namespace App\Core\Localization\Enums;

enum Direction: string
{
    case LTR = 'ltr';
    case RTL = 'rtl';

    /**
     * RTL locales — expand in later Localization/Markets phase.
     *
     * @var array<int, string>
     */
    private const RTL_LOCALES = ['ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi'];

    public static function fromLocale(string $locale): self
    {
        // Extract the primary language subtag (e.g. "ar" from "ar-SA")
        $lang = strtolower(explode('-', $locale)[0]);

        return in_array($lang, self::RTL_LOCALES, strict: true)
            ? self::RTL
            : self::LTR;
    }
}
