<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Exceptions\PluginPackageRejectedException;
use App\Core\Plugin\Services\PluginSignatureVerifier;
use App\Core\Plugin\Services\PluginZipInstaller;
use Illuminate\Support\Facades\File;
use Tests\Support\PluginTestFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Proves the "HyperStore Plugin Package v1" integrity contract (ADR-0134)
 * end-to-end against real ZIP packages: signed-and-valid succeeds, tampered
 * content fails, unlisted extra files fail, and invalid signatures are
 * always a hard rejection (never silently downgraded to unverified).
 */
class PluginPackageIntegrityTest extends TestCase
{
    use PluginTestFixtures;

    private string $stagingBase;

    private PluginZipInstaller $installer;

    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stagingBase = storage_path('app/plugin-staging-test-integrity');
        File::ensureDirectoryExists($this->stagingBase);
        $this->installer = new PluginZipInstaller(new PluginSignatureVerifier);

        $keyPair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($keyPair);
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);

        config(['plugins.trusted_publishers' => ['test-publisher' => base64_encode($this->publicKey)]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingBase);
        parent::tearDown();
    }

    /**
     * Builds a real signed package: hashes every real file in the fixture
     * source tree, signs the canonical payload, and adds plugin.sig.
     */
    private function buildSignedZip(?callable $tamperFiles = null, ?callable $tamperSigPayload = null): string
    {
        $sourcePath = $this->validPluginSourcePath();
        $files = $this->hashDirectory($sourcePath);

        if ($tamperFiles !== null) {
            $files = $tamperFiles($files);
        }

        $manifestJson = file_get_contents($sourcePath.'/plugin.json');
        $manifestSha256 = hash('sha256', (string) $manifestJson);

        $payload = [
            'plugin_id' => 'test-fixture-plugin',
            'version' => '1.0.0',
            'manifest_sha256' => $manifestSha256,
            'files' => $files,
        ];
        ksort($payload['files']);

        if ($tamperSigPayload !== null) {
            $payload = $tamperSigPayload($payload);
        }

        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = base64_encode(sodium_crypto_sign_detached($canonical, $this->secretKey));

        $sig = $payload + [
            'signature_algorithm' => 'ed25519',
            'publisher_key_id' => 'test-publisher',
            'signature' => $signature,
        ];

        $zipPath = tempnam(sys_get_temp_dir(), 'signed_plugin_').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->addSourceTreeToZip($zip, $sourcePath);
        $zip->addFromString('plugin.sig', json_encode($sig, JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $zipPath;
    }

    /**
     * @return array<string, string>
     */
    private function hashDirectory(string $dir, string $prefix = ''): array
    {
        $hashes = [];
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..' || $item === 'plugin.sig') {
                continue;
            }
            $full = $dir.'/'.$item;
            $rel = $prefix === '' ? $item : $prefix.'/'.$item;
            if (is_dir($full)) {
                $hashes += $this->hashDirectory($full, $rel);
            } else {
                $hashes[$rel] = hash_file('sha256', $full);
            }
        }

        return $hashes;
    }

    private function addSourceTreeToZip(ZipArchive $zip, string $dir, string $prefix = ''): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..' || $item === 'plugin.sig') {
                continue;
            }
            $full = $dir.'/'.$item;
            $rel = $prefix === '' ? $item : $prefix.'/'.$item;
            if (is_dir($full)) {
                $this->addSourceTreeToZip($zip, $full, $rel);
            } else {
                $zip->addFile($full, $rel);
            }
        }
    }

    public function test_a_validly_signed_package_installs_with_verified_trust(): void
    {
        $zip = $this->buildSignedZip();

        $result = $this->installer->stageAndValidate($zip, $this->stagingBase);

        $this->assertTrue($result->signatureValid);
        $this->assertSame('test-publisher', $result->trustedKeyId);
    }

    public function test_tampered_file_content_after_signing_is_rejected(): void
    {
        $sourcePath = $this->validPluginSourcePath();
        $zip = $this->buildSignedZip();

        // Re-open and corrupt one file's content after the signature was computed.
        $tampered = tempnam(sys_get_temp_dir(), 'tampered_').'.zip';
        copy($zip, $tampered);
        $za = new ZipArchive;
        $za->open($tampered);
        $za->deleteName('src/TestFixturePluginServiceProvider.php');
        $za->addFromString('src/TestFixturePluginServiceProvider.php', '<?php /* tampered */');
        $za->close();

        $this->expectException(PluginPackageRejectedException::class);
        $this->installer->stageAndValidate($tampered, $this->stagingBase);
    }

    public function test_an_unlisted_extra_file_bypasses_nothing_and_is_rejected(): void
    {
        $zip = $this->buildSignedZip();

        $tampered = tempnam(sys_get_temp_dir(), 'extra_file_').'.zip';
        copy($zip, $tampered);
        $za = new ZipArchive;
        $za->open($tampered);
        $za->addFromString('src/SneakyExtra.php', '<?php /* not in the signed file list */');
        $za->close();

        $this->expectException(PluginPackageRejectedException::class);
        $this->expectExceptionMessageMatches('/not listed in plugin\.sig/');
        $this->installer->stageAndValidate($tampered, $this->stagingBase);
    }

    public function test_invalid_signature_is_a_hard_rejection_even_though_a_signature_is_present(): void
    {
        // Sign with a key NOT in the trusted_publishers config.
        $untrustedKeyPair = sodium_crypto_sign_keypair();
        $untrustedSecret = sodium_crypto_sign_secretkey($untrustedKeyPair);

        $originalSecret = $this->secretKey;
        $this->secretKey = $untrustedSecret;
        $zip = $this->buildSignedZip();
        $this->secretKey = $originalSecret;

        config(['plugins.allow_unsigned' => true]); // even with unsigned allowed, invalid signature must still hard-reject

        $this->expectException(PluginPackageRejectedException::class);
        $this->expectExceptionMessageMatches('/does not verify/');
        $this->installer->stageAndValidate($zip, $this->stagingBase);
    }

    public function test_a_file_missing_from_the_archive_but_declared_in_the_signed_list_is_rejected(): void
    {
        $zip = $this->buildSignedZip(tamperFiles: function (array $files): array {
            $files['src/GhostFile.php'] = hash('sha256', 'ghost');

            return $files;
        });

        $this->expectException(PluginPackageRejectedException::class);
        $this->expectExceptionMessageMatches('/missing from the archive/');
        $this->installer->stageAndValidate($zip, $this->stagingBase);
    }
}
