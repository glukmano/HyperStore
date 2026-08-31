<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\CarrierCredential;
use Tests\TestCase;

class CarrierProviderAndCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantA = Tenant::create(['name' => 'Carrier Tenant A', 'slug' => 'carrier-a', 'status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'Carrier Tenant B', 'slug' => 'carrier-b', 'status' => 'active']);
    }

    public function test_carrier_credentials_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $carrier = Carrier::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'DHL_EXPRESS',
            'name' => 'DHL Express',
            'provider_code' => 'manual',
            'status' => 'active',
        ]);

        $cred = new CarrierCredential([
            'carrier_id' => $carrier->id,
            'environment' => 'production',
        ]);
        $secretPayload = ['api_key' => 'SECRET_KEY_123', 'client_secret' => 'SECRET_PASS_456'];
        $cred->setDecryptedCredentials($secretPayload);
        $cred->save();

        // 1. Assert DB raw column is encrypted and does not contain plaintext secrets
        $rawRow = DB::table('carrier_credentials')->where('id', $cred->id)->first();
        $this->assertNotNull($rawRow);
        $this->assertStringNotContainsString('SECRET_KEY_123', $rawRow->encrypted_credentials);
        $this->assertStringNotContainsString('SECRET_PASS_456', $rawRow->encrypted_credentials);

        // 2. Assert decrypted helper works
        $decrypted = $cred->getDecryptedCredentials();
        $this->assertSame('SECRET_KEY_123', $decrypted['api_key']);

        // 3. Assert Model serialization hides secrets
        $serialized = $cred->toArray();
        $this->assertArrayNotHasKey('encrypted_credentials', $serialized);
    }
}
