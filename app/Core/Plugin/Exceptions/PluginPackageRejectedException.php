<?php

declare(strict_types=1);

namespace App\Core\Plugin\Exceptions;

use RuntimeException;

final class PluginPackageRejectedException extends RuntimeException
{
    public static function reason(string $reason): self
    {
        return new self("Plugin package rejected: {$reason}");
    }
}
