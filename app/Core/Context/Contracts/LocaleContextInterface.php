<?php

declare(strict_types=1);

namespace App\Core\Context\Contracts;

interface LocaleContextInterface
{
    public function getLocale(): ?string;

    public function getDirection(): ?string; // 'ltr' | 'rtl'

    public function isResolved(): bool;
}
