<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Version
    |--------------------------------------------------------------------------
    |
    | Checked against a plugin manifest's compatibility.platform semver
    | constraint at install/enable time.
    |
    */
    'platform_version' => env('PLUGINS_PLATFORM_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | Allow Unsigned Plugins
    |--------------------------------------------------------------------------
    |
    | Whether a plugin package with no plugin.sig file may still be installed
    | (as trust_level 'unverified'). A present-but-invalid signature is always
    | a hard rejection regardless of this setting (ADR-0134).
    |
    */
    'allow_unsigned' => env('PLUGINS_ALLOW_UNSIGNED', env('APP_ENV', 'production') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Publisher Public Keys
    |--------------------------------------------------------------------------
    |
    | Map of publisher_key_id => base64-encoded libsodium (Ed25519) public key.
    | No certificate authority — an admin-managed allowlist (ADR-0134).
    |
    */
    'trusted_publishers' => [
        // 'hyperstore-official' => env('PLUGINS_TRUSTED_KEY_HYPERSTORE_OFFICIAL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Publisher Trust Tiers
    |--------------------------------------------------------------------------
    |
    | Map of publisher_key_id => 'official'|'verified_third_party'. Any key
    | not listed here defaults to 'verified_third_party' when it verifies.
    |
    */
    'publisher_trust_tiers' => [
        // 'hyperstore-official' => 'official',
    ],

    /*
    |--------------------------------------------------------------------------
    | ZIP Extraction Limits
    |--------------------------------------------------------------------------
    |
    | Enforced against ACTUAL decompressed bytes during streaming extraction,
    | never against ZIP-declared metadata (ADR-0134).
    |
    */
    'zip' => [
        'max_total_uncompressed_bytes' => 50 * 1024 * 1024,
        'max_entry_uncompressed_bytes' => 10 * 1024 * 1024,
        'max_entry_count' => 5000,
        'max_compression_ratio' => 100,
        'max_manifest_bytes' => 256 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Boot Failure Threshold
    |--------------------------------------------------------------------------
    |
    | A plugin auto-transitions to 'disabled' after this many consecutive
    | register()/boot() exceptions, to stop a per-request crash-retry loop.
    |
    */
    'max_consecutive_boot_failures' => 3,

];
