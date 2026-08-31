<?php

declare(strict_types=1);

namespace App\Core\Modular\Contracts;

interface ModuleInterface
{
    public function getName(): string;

    public function getPath(): string;

    public function getNamespace(): string;

    /**
     * @return array<int, string>
     */
    public function getDependencies(): array;

    public function isEnabled(): bool;

    public function register(): void;

    public function boot(): void;
}
