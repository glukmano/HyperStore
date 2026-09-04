<?php

declare(strict_types=1);

namespace Tests\Support;

use ZipArchive;

/**
 * Builds real, on-the-fly plugin ZIP packages for Phase-16 Plugin SDK tests —
 * from the pre-built tests/Fixtures/plugin-packages/valid-plugin/ source tree
 * (which has a genuine Composer-generated vendor/autoload.php, not a fake
 * stub), optionally overriding manifest fields or injecting malicious entries.
 */
trait PluginTestFixtures
{
    protected function validPluginSourcePath(): string
    {
        return base_path('tests/Fixtures/plugin-packages/valid-plugin');
    }

    /**
     * @param  array<string, mixed>  $manifestOverrides
     */
    protected function buildPluginZip(array $manifestOverrides = [], ?string $sourcePath = null): string
    {
        $sourcePath ??= $this->validPluginSourcePath();

        $manifestJson = file_get_contents($sourcePath.'/plugin.json');
        $manifest = json_decode((string) $manifestJson, associative: true, flags: JSON_THROW_ON_ERROR);
        $manifest = array_merge($manifest, $manifestOverrides);
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $zipPath = tempnam(sys_get_temp_dir(), 'plugin_test_').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $this->addDirectoryToZip($zip, $sourcePath, '');
        $zip->addFromString('plugin.json', (string) $manifestJson);

        $zip->close();

        return $zipPath;
    }

    private function addDirectoryToZip(ZipArchive $zip, string $dir, string $prefix): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'plugin.json') {
                continue;
            }

            $fullPath = $dir.'/'.$item;
            $zipPath = $prefix === '' ? $item : $prefix.'/'.$item;

            if (is_dir($fullPath)) {
                $this->addDirectoryToZip($zip, $fullPath, $zipPath);
            } else {
                $zip->addFile($fullPath, $zipPath);
            }
        }
    }

    /**
     * A ZIP whose one entry attempts to escape the extraction root via ../.
     */
    protected function buildZipSlipZip(): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'plugin_zipslip_').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('plugin.json', json_encode($this->minimalManifest(), JSON_PRETTY_PRINT));
        $zip->addFromString('../../../tmp/evil.php', '<?php echo "pwned"; ?>');
        $zip->close();

        return $zipPath;
    }

    /**
     * A ZIP with an entry count exceeding the configured limit.
     */
    protected function buildTooManyEntriesZip(int $count): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'plugin_bomb_count_').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('plugin.json', json_encode($this->minimalManifest(), JSON_PRETTY_PRINT));
        for ($i = 0; $i < $count; $i++) {
            $zip->addFromString("src/file{$i}.txt", 'x');
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * A ZIP with a highly compressible file whose actual decompressed size
     * exceeds the per-entry limit — proves the byte-counted streaming cap,
     * not a declared-size check, is what actually rejects it.
     */
    protected function buildArchiveBombZip(int $decompressedBytes): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'plugin_bomb_').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('plugin.json', json_encode($this->minimalManifest(), JSON_PRETTY_PRINT));
        $zip->addFromString('src/bomb.txt', str_repeat('A', $decompressedBytes));
        $zip->setCompressionName('src/bomb.txt', ZipArchive::CM_DEFLATE, 9);
        $zip->close();

        return $zipPath;
    }

    /**
     * A ZIP containing one entry flagged as a Unix symlink via external_attr.
     */
    protected function buildSymlinkZip(): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'plugin_symlink_').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('plugin.json', json_encode($this->minimalManifest(), JSON_PRETTY_PRINT));
        $zip->addFromString('src/evil-link', '/etc/passwd');

        $symlinkMode = 0xA000 | 0777;
        $zip->setExternalAttributesName('src/evil-link', ZipArchive::OPSYS_UNIX, $symlinkMode << 16);

        $zip->close();

        return $zipPath;
    }

    /**
     * @return array<string, mixed>
     */
    protected function minimalManifest(): array
    {
        return [
            'manifest_version' => 1,
            'id' => 'malicious-test-plugin',
            'name' => 'Malicious Test Plugin',
            'version' => '1.0.0',
            'author' => 'Test',
            'license' => 'MIT',
            'compatibility' => ['platform' => '*', 'php' => '*'],
            'dependencies' => [],
            'requested_permissions' => [],
            'capabilities' => [],
            'entrypoint' => 'Plugins\\MaliciousTestPlugin\\MaliciousTestPluginServiceProvider',
            'namespace' => 'Plugins\\MaliciousTestPlugin',
            'migrations' => 'database/migrations',
        ];
    }
}
