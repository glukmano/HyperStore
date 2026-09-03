<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\PlatformSettingsServiceInterface;
use App\Core\SuperAdmin\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;

final readonly class PlatformSettingsService implements PlatformSettingsServiceInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        /** @var ?PlatformSetting $setting */
        $setting = PlatformSetting::where('key', $key)->first();
        if ($setting === null) {
            return $default;
        }

        if ($setting->is_encrypted) {
            return Crypt::decryptString($setting->value);
        }

        return $setting->value;
    }

    public function set(string $key, mixed $value, bool $encrypt = false, ?int $userId = null): PlatformSetting
    {
        $stringValue = is_string($value) ? $value : (string) json_encode($value);
        $storedValue = $encrypt ? Crypt::encryptString($stringValue) : $stringValue;

        /** @var PlatformSetting $setting */
        $setting = PlatformSetting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $storedValue,
                'is_encrypted' => $encrypt,
                'updated_by_user_id' => $userId,
            ]
        );

        return $setting;
    }
}
