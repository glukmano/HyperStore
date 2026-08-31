<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\TestAlpha;

use App\Core\Modular\ModuleServiceProvider;

/**
 * Test fixture service provider for the TestAlpha module.
 *
 * Used exclusively in tests to verify module discovery, registration, and boot lifecycle.
 * Must NOT be loaded in production.
 */
class TestAlphaServiceProvider extends ModuleServiceProvider
{
    /** Tracks whether register() was called — readable by tests. */
    public static bool $registered = false;

    /** Tracks whether boot() was called — readable by tests. */
    public static bool $booted = false;

    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        self::$registered = true;

        // Bind a simple marker in the container so tests can verify DI works
        $this->app->bind('test.alpha.marker', fn () => 'TestAlpha::register');
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
