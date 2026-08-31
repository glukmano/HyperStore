<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Modules\Shipping\Services\CredentialDecryptionException;

class CarrierCredential extends Model
{
    protected $table = 'carrier_credentials';

    protected $fillable = [
        'carrier_id',
        'environment',
        'encrypted_credentials',
    ];

    protected $hidden = [
        'encrypted_credentials',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'carrier_id');
    }

    /**
     * Store secrets securely encrypted.
     *
     * @param  array<string, mixed>  $payload
     */
    public function setDecryptedCredentials(array $payload): void
    {
        $this->encrypted_credentials = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Retrieve decrypted secrets securely for provider execution.
     *
     * @return array<string, mixed>
     *
     * @throws CredentialDecryptionException
     */
    public function getDecryptedCredentials(): array
    {
        if (empty($this->encrypted_credentials)) {
            return [];
        }

        try {
            $json = Crypt::decryptString($this->encrypted_credentials);

            return (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new CredentialDecryptionException('Failed to securely decrypt carrier credentials: '.$e->getMessage(), 0, $e);
        }
    }
}
