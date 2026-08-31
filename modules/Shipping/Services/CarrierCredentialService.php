<?php

declare(strict_types=1);

namespace Modules\Shipping\Services;

use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\CarrierCredential;
use RuntimeException;

class CredentialDecryptionException extends RuntimeException {}

class CarrierCredentialService
{
    /**
     * Store encrypted credentials.
     *
     * @param  array<string, mixed>  $secrets
     */
    public function store(Carrier $carrier, string $environment, array $secrets): CarrierCredential
    {
        $cred = CarrierCredential::firstOrNew([
            'carrier_id' => $carrier->id,
            'environment' => $environment,
        ]);

        $cred->setDecryptedCredentials($secrets);
        $cred->save();

        return $cred;
    }

    /**
     * Safely decrypt credentials.
     *
     * @return array<string, mixed>
     *
     * @throws CredentialDecryptionException
     */
    public function getDecrypted(CarrierCredential $credential): array
    {
        return $credential->getDecryptedCredentials();
    }
}
