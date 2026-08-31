<?php

declare(strict_types=1);

namespace App\Core\Localization\Contracts;

use App\Core\Localization\Enums\Direction;

interface LocaleManagerInterface
{
    public function setLocale(string $locale): void;

    public function getLocale(): string;

    public function getDirection(): Direction;

    public function isRtl(): bool;

    /**
     * @return array<int, string>
     */
    public function getSupportedLocales(): array;
}
