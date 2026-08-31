<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\TestGamma;

use App\Core\Modular\ModuleServiceProvider;

/**
 * Test fixture for a DISABLED module.
 * Verifies that disabled modules are discovered but never registered or booted.
 */
class TestGammaServiceProvider extends ModuleServiceProvider
{
    public static bool $registered = false;

    public static bool $booted = false;

    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        self::$registered = true;
    }

    public function boot(): void
    {
        self::$booted = true;
    }

    public static function reset(): void
    {
        self::$registered = false;
        self::$booted = false;
    }
}
