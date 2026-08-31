<?php

declare(strict_types=1);

namespace App\Core\Localization;

use App\Core\Localization\Contracts\LocaleManagerInterface;
use App\Core\Localization\Enums\Direction;
use Illuminate\Contracts\Foundation\Application;

/**
 * LocaleManager: Manages runtime locale and text direction.
 *
 * Sets App locale and derives direction from locale via Direction enum.
 * Supported locales are configured via config('app.supported_locales').
 */
final class LocaleManager implements LocaleManagerInterface
{
    private string $locale;

    private Direction $direction;

    public function __construct(private readonly Application $app)
    {
        $this->locale = $app->getLocale();
        $this->direction = Direction::fromLocale($this->locale);
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
        $this->direction = Direction::fromLocale($locale);
        $this->app->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getDirection(): Direction
    {
        return $this->direction;
    }

    public function isRtl(): bool
    {
        return $this->direction === Direction::RTL;
    }

    /**
     * @return array<int, string>
     */
    public function getSupportedLocales(): array
    {
        /** @var array<int, string> $supported */
        $supported = config('app.supported_locales', ['en']);

        return $supported;
    }
}
