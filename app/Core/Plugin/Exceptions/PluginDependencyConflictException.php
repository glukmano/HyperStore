<?php

declare(strict_types=1);

namespace App\Core\Plugin\Exceptions;

use App\Core\Plugin\DTOs\PluginDependencyConflict;
use RuntimeException;

final class PluginDependencyConflictException extends RuntimeException
{
    /**
     * @param  list<PluginDependencyConflict>  $conflicts
     */
    public function __construct(private readonly array $conflicts)
    {
        $messages = array_map(fn (PluginDependencyConflict $c): string => $c->describe(), $conflicts);
        parent::__construct('Plugin dependency conflict(s) detected: '.implode(' ', $messages));
    }

    /**
     * @return list<PluginDependencyConflict>
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }
}
