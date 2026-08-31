<?php

declare(strict_types=1);

namespace App\Core\Context\DTOs;

use App\Core\Context\Contracts\LocaleContextInterface;

final class LocaleContext implements LocaleContextInterface
{
    private function __construct(
        private readonly ?string $locale,
        private readonly ?string $direction, // 'ltr' | 'rtl'
        private readonly bool $resolved,
    ) {}

    public static function from(string $locale, string $direction = 'ltr'): self
    {
        return new self($locale, $direction, true);
    }

    public static function unresolved(): self
    {
        return new self(null, null, false);
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function getDirection(): ?string
    {
        return $this->direction;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
