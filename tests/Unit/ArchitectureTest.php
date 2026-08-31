<?php

declare(strict_types=1);
use Illuminate\Console\Command;

// ── Architecture Tests (Pest Arch) ────────────────────────────────────────────
//
// These tests enforce structural invariants across the codebase.
// They run without touching the database.

// ── Core Namespace Integrity ──────────────────────────────────────────────────

arch('Core classes use strict types')
    ->expect('App\Core')
    ->toUseStrictTypes();

arch('App classes use strict types')
    ->expect('App')
    ->toUseStrictTypes();

// ── No Float Money ────────────────────────────────────────────────────────────
// ADR-0004: Money must never use float.
// Phase 01 has no monetary code, so this asserts the invariant holds at foundation.

arch('no float type hints exist in Core')
    ->expect('App\Core')
    ->not->toUse('float');

// ── Modular Boundaries ────────────────────────────────────────────────────────

arch('ModuleKernel does not depend on business modules')
    ->expect('App\Core\Modular')
    ->not->toUse('Modules');

arch('Context layer does not depend on business modules')
    ->expect('App\Core\Context')
    ->not->toUse('Modules');

arch('Localization layer does not depend on business modules')
    ->expect('App\Core\Localization')
    ->not->toUse('Modules');

// ── Interface Contracts ───────────────────────────────────────────────────────

arch('ModuleInterface is an interface')
    ->expect('App\Core\Modular\Contracts\ModuleInterface')
    ->toBeInterface();

arch('ModuleRegistryInterface is an interface')
    ->expect('App\Core\Modular\Contracts\ModuleRegistryInterface')
    ->toBeInterface();

arch('ModuleKernelInterface is an interface')
    ->expect('App\Core\Modular\Contracts\ModuleKernelInterface')
    ->toBeInterface();

arch('FeatureManagerInterface is an interface')
    ->expect('App\Core\Features\Contracts\FeatureManagerInterface')
    ->toBeInterface();

arch('AuditManagerInterface is an interface')
    ->expect('App\Core\Audit\Contracts\AuditManagerInterface')
    ->toBeInterface();

// ── DTOs are Final ────────────────────────────────────────────────────────────

arch('Context DTOs are final')
    ->expect('App\Core\Context\DTOs')
    ->toBeFinal();

arch('ModuleManifest DTO is final')
    ->expect('App\Core\Modular\DTOs\ModuleManifest')
    ->toBeFinal();

// ── Commands are Artisan commands ─────────────────────────────────────────────

arch('ModuleListCommand extends Illuminate Command')
    ->expect('App\Core\Modular\Commands\ModuleListCommand')
    ->toExtend(Command::class);
