<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\TestBeta;

use App\Core\Modular\ModuleServiceProvider;

/**
 * Test fixture service provider for the TestBeta module.
 * Depends on TestAlpha — verifies correct dependency ordering.
 */
class TestBetaServiceProvider extends ModuleServiceProvider
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
