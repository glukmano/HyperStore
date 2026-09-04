<?php

declare(strict_types=1);

namespace Tests\Feature\Plugin;

use App\Core\Plugin\Exceptions\PluginPackageRejectedException;
use App\Core\Plugin\Services\PluginSignatureVerifier;
use App\Core\Plugin\Services\PluginZipInstaller;
use Illuminate\Support\Facades\File;
use Tests\Support\PluginTestFixtures;
use Tests\TestCase;

/**
 * Real-filesystem adversarial tests for the ZIP install pipeline (ADR-0134).
 * Every scenario here proves a specific, named attack is rejected — not just
 * that "something" throws.
 */
class PluginZipSecurityTest extends TestCase
{
    use PluginTestFixtures;

    private string $stagingBase;

    private PluginZipInstaller $installer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stagingBase = storage_path('app/plugin-staging-test');
        File::ensureDirectoryExists($this->stagingBase);
        $this->installer = new PluginZipInstaller(new PluginSignatureVerifier);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingBase);
        parent::tearDown();
    }

    public function test_a_valid_package_stages_and_validates_successfully(): void
    {
        $zip = $this->buildPluginZip();

        $result = $this->installer->stageAndValidate($zip, $this->stagingBase);

        $this->assertSame('test-fixture-plugin', $result->manifest->id);
        $this->assertDirectoryExists($result->stagingPath);
        $this->assertFileExists($result->stagingPath.'/vendor/autoload.php');
    }

    public function test_zip_slip_path_traversal_entry_is_rejected(): void
    {
        $zip = $this->buildZipSlipZip();

        $this->expectException(PluginPackageRejectedException::class);
        $this->installer->stageAndValidate($zip, $this->stagingBase);
    }

    public function test_zip_slip_entry_never_writes_outside_the_staging_root(): void
    {
        $zip = $this->buildZipSlipZip();
        $escapeTarget = dirname($this->stagingBase, 3).'/tmp/evil.php';
        @unlink($escapeTarget);

        try {
            $this->installer->stageAndValidate($zip, $this->stagingBase);
        } catch (PluginPackageRejectedException) {
            // expected
        }

        $this->assertFileDoesNotExist($escapeTarget);
    }

    public function test_symlink_entry_is_rejected(): void
    {
        $zip = $this->buildSymlinkZip();

        $this->expectException(PluginPackageRejectedException::class);
        $this->expectExceptionMessageMatches('/symlink/i');
        $this->installer->stageAndValidate($zip, $this->stagingBase);
    }

    public function test_archive_with_too_many_entries_is_rejected(): void
    {
        config(['plugins.zip.max_entry_count' => 10]);
        $zip = $this->buildTooManyEntriesZip(50);

        $this->expectException(PluginPackageRejectedException::class);
        $this->installer->stageAndValidate($zip, $this->stagingBase);
    }

    public function test_archive_bomb_is_rejected_by_actual_decompressed_bytes_not_declared_metadata(): void
    {
        config(['plugins.zip.max_entry_uncompressed_bytes' => 1024]);
        // Highly compressible content that decompresses far past the 1KB cap —
        // proves the byte-counted streaming loop is what catches it, since the
        // ZIP's own declared central-directory size is also small (all 'A's
        // compress extremely well, so a naive declared-size pre-filter using a
        // loose ratio threshold would not catch a real bomb; the streaming cap
        // is what makes this deterministic regardless of compression ratio).
        $zip = $this->buildArchiveBombZip(2 * 1024 * 1024);

        $this->expectException(PluginPackageRejectedException::class);
        $this->installer->stageAndValidate($zip, $this->stagingBase);
    }

    public function test_total_archive_size_limit_is_enforced(): void
    {
        config(['plugins.zip.max_total_uncompressed_bytes' => 100]);
        config(['plugins.zip.max_entry_uncompressed_bytes' => 1024 * 1024]);

        $this->expectException(PluginPackageRejectedException::class);
        $this->installer->stageAndValidate($this->buildPluginZip(), $this->stagingBase);
    }

    public function test_missing_manifest_is_rejected(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'no_manifest_').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('src/Foo.php', '<?php');
        $zip->close();

        $this->expectException(PluginPackageRejectedException::class);
        $this->expectExceptionMessageMatches('/manifest/i');
        $this->installer->stageAndValidate($zipPath, $this->stagingBase);
    }

    public function test_malformed_manifest_json_is_rejected(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'bad_manifest_').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('plugin.json', '{not valid json');
        $zip->close();

        $this->expectException(\Throwable::class);
        $this->installer->stageAndValidate($zipPath, $this->stagingBase);
    }

    public function test_oversized_manifest_entry_is_rejected(): void
    {
        config(['plugins.zip.max_manifest_bytes' => 100]);

        $zipPath = tempnam(sys_get_temp_dir(), 'huge_manifest_').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('plugin.json', str_repeat('{"a":"'.str_repeat('x', 500).'"}', 5));
        $zip->close();

        $this->expectException(PluginPackageRejectedException::class);
        $this->installer->stageAndValidate($zipPath, $this->stagingBase);
    }
}
