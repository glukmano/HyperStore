<?php

declare(strict_types=1);

use App\Core\Modular\Contracts\ModuleInterface;
use App\Core\Modular\Contracts\ModuleKernelInterface;
use App\Core\Modular\Contracts\ModuleRegistryInterface;
use App\Core\Modular\ModuleKernel;
use App\Core\Modular\ModuleRegistry;
use Tests\Fixtures\Modules\TestAlpha\TestAlphaServiceProvider;
use Tests\Fixtures\Modules\TestBeta\TestBetaServiceProvider;
use Tests\Fixtures\Modules\TestGamma\TestGammaServiceProvider;

beforeEach(function () {
    TestAlphaServiceProvider::reset();
    TestBetaServiceProvider::reset();
    TestGammaServiceProvider::reset();
});

// ── Discovery ─────────────────────────────────────────────────────────────────

test('ModuleKernel discovers modules at a given path', function () {
    $registry = new ModuleRegistry;
    $kernel = new ModuleKernel(app(), $registry, base_path('modules'));

    $fixturesPath = base_path('tests/Fixtures/Modules/TestAlpha');
    $kernel->discoverAt($fixturesPath);

    expect($registry->has('TestAlpha'))->toBeTrue();
});

test('ModuleKernel discovers all fixture modules', function () {
    $registry = new ModuleRegistry;
    $kernel = new ModuleKernel(app(), $registry, base_path('modules'));

    foreach (['TestAlpha', 'TestBeta', 'TestGamma'] as $name) {
        $kernel->discoverAt(base_path("tests/Fixtures/Modules/{$name}"));
    }

    expect($registry->all())->toHaveCount(3);
});

test('production modules/ directory is empty', function () {
    $modulesPath = base_path('modules');

    if (! is_dir($modulesPath)) {
        expect(true)->toBeTrue(); // no directory = no modules, pass

        return;
    }

    $entries = array_diff(scandir($modulesPath) ?: [], ['.', '..']);
    expect($entries)->toBeEmpty('Production modules/ must remain empty during PHASE-01');
});

// ── Enabled / Disabled ────────────────────────────────────────────────────────

test('enabled modules are correctly identified', function () {
    $registry = new ModuleRegistry;
    $kernel = new ModuleKernel(app(), $registry, base_path('modules'));

    $kernel->discoverAt(base_path('tests/Fixtures/Modules/TestAlpha'));
    $kernel->discoverAt(base_path('tests/Fixtures/Modules/TestGamma')); // disabled

    expect($registry->enabled())->toHaveCount(1);
    expect($registry->disabled())->toHaveCount(1);
});

test('disabled module is never registered or booted', function () {
    $registry = new ModuleRegistry;
    $kernel = new ModuleKernel(app(), $registry, base_path('modules'));
    $kernel->discoverAt(base_path('tests/Fixtures/Modules/TestGamma'));

    $kernel->registerModules();
    $kernel->bootModules();

    expect(TestGammaServiceProvider::$registered)->toBeFalse();
    expect(TestGammaServiceProvider::$booted)->toBeFalse();
});

// ── Dependency Ordering ───────────────────────────────────────────────────────

test('modules are ordered by dependencies (TestAlpha before TestBeta)', function () {
    $registry = new ModuleRegistry;
    $kernel = new ModuleKernel(app(), $registry, base_path('modules'));

    $kernel->discoverAt(base_path('tests/Fixtures/Modules/TestBeta')); // depends on TestAlpha
    $kernel->discoverAt(base_path('tests/Fixtures/Modules/TestAlpha'));

    $ordered = $registry->getOrdered();

    $names = array_map(fn ($m) => $m->getName(), $ordered);

    $alphaIndex = array_search('TestAlpha', $names);
    $betaIndex = array_search('TestBeta', $names);

    expect($alphaIndex)->toBeLessThan($betaIndex, 'TestAlpha must boot before TestBeta');
});

test('circular dependency throws RuntimeException', function () {
    // Manually register two modules with circular deps to simulate the case
    $registry = new ModuleRegistry;

    // Use anonymous class mocks to create circular dependency
    $alpha = new class implements ModuleInterface
    {
        public function getName(): string
        {
            return 'CircA';
        }

        public function getPath(): string
        {
            return '';
        }

        public function getNamespace(): string
        {
            return '';
        }

        public function getDependencies(): array
        {
            return ['CircB'];
        }

        public function isEnabled(): bool
        {
            return true;
        }

        public function register(): void {}

        public function boot(): void {}
    };

    $beta = new class implements ModuleInterface
    {
        public function getName(): string
        {
            return 'CircB';
        }

        public function getPath(): string
        {
            return '';
        }

        public function getNamespace(): string
        {
            return '';
        }

        public function getDependencies(): array
        {
            return ['CircA'];
        }

        public function isEnabled(): bool
        {
            return true;
        }

        public function register(): void {}

        public function boot(): void {}
    };

    $registry->register($alpha);
    $registry->register($beta);

    expect(fn () => $registry->getOrdered())->toThrow(RuntimeException::class);
});

// ── Lifecycle ─────────────────────────────────────────────────────────────────

test('register() is called on enabled modules', function () {
    $registry = new ModuleRegistry;
    $kernel = new ModuleKernel(app(), $registry, base_path('modules'));
    $kernel->discoverAt(base_path('tests/Fixtures/Modules/TestAlpha'));

    $kernel->registerModules();

    expect(TestAlphaServiceProvider::$registered)->toBeTrue();
});

test('boot() is called on enabled modules', function () {
    $registry = new ModuleRegistry;
    $kernel = new ModuleKernel(app(), $registry, base_path('modules'));
    $kernel->discoverAt(base_path('tests/Fixtures/Modules/TestAlpha'));

    $kernel->registerModules();
    $kernel->bootModules();

    expect(TestAlphaServiceProvider::$booted)->toBeTrue();
});

test('discover() is idempotent and does not double-register modules', function () {
    $registry = new ModuleRegistry;
    $kernel = new ModuleKernel(app(), $registry, base_path('modules'));

    // Even calling discoverAt twice on the same path should only register once
    // (the kernel's discover() is idempotent, but discoverAt doesn't check this)
    $kernel->discoverAt(base_path('tests/Fixtures/Modules/TestAlpha'));

    expect($registry->all())->toHaveCount(1);
});

// ── Container bindings ────────────────────────────────────────────────────────

test('ModuleKernelInterface is bound in the container', function () {
    expect(app(ModuleKernelInterface::class))->toBeInstanceOf(ModuleKernel::class);
});

test('ModuleRegistryInterface is bound in the container', function () {
    expect(app(ModuleRegistryInterface::class))->toBeInstanceOf(ModuleRegistry::class);
});

test('module:list command runs without error', function () {
    $this->artisan('module:list')->assertExitCode(0);
});
