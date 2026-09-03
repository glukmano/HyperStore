<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

use App\Core\SuperAdmin\Models\PlatformSetting;

interface PlatformSettingsServiceInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, bool $encrypt = false, ?int $userId = null): PlatformSetting;
}
