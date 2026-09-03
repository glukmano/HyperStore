<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\SuperAdmin\Contracts\PlatformSettingsServiceInterface;
use App\Core\SuperAdmin\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSettingsEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_encrypted_setting_stores_ciphertext_in_database(): void
    {
        $service = app(PlatformSettingsServiceInterface::class);

        $setting = $service->set('stripe_api_key', 'sk_live_secret_12345', encrypt: true);

        $this->assertTrue($setting->is_encrypted);

        // Direct DB inspection asserts ciphertext, not plaintext!
        $rawInDb = PlatformSetting::where('key', 'stripe_api_key')->value('value');
        $this->assertNotSame('sk_live_secret_12345', $rawInDb);
        $this->assertStringStartsWith('eyJ', $rawInDb); // Standard Laravel encrypted payload base64 header

        // Retrieval through service provides decrypted plain value
        $decrypted = $service->get('stripe_api_key');
        $this->assertSame('sk_live_secret_12345', $decrypted);
    }
}
