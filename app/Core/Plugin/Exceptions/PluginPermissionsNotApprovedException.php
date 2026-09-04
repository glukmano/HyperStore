<?php

declare(strict_types=1);

namespace App\Core\Plugin\Exceptions;

use RuntimeException;

final class PluginPermissionsNotApprovedException extends RuntimeException
{
    public static function forPlugin(string $pluginId): self
    {
        return new self("Plugin [{$pluginId}] requests capabilities/permissions that have not been explicitly approved. Approve them before enabling.");
    }
}
