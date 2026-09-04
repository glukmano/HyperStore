<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Plugin\DTOs\PluginManifest;
use App\Core\Plugin\DTOs\PluginPackageIntegrity;
use App\Core\Plugin\DTOs\PluginStagingResult;
use App\Core\Plugin\Exceptions\PluginPackageRejectedException;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Stages and validates a plugin ZIP package. Extraction is entry-by-entry via
 * ZipArchive::getStream(), copied through a byte-counted bounded read loop —
 * ZIP-declared sizes are used only as a fast pre-filter, never as the
 * authoritative guarantee, since central-directory metadata is attacker-
 * controlled (ADR-0134).
 */
final class PluginZipInstaller
{
    private const int READ_CHUNK_BYTES = 8192;

    private const int S_IFLNK = 0xA000;

    public function __construct(
        private readonly PluginSignatureVerifier $signatureVerifier,
    ) {}

    public function stageAndValidate(string $zipPath, string $stagingBasePath): PluginStagingResult
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw PluginPackageRejectedException::reason('Unable to open ZIP archive.');
        }

        try {
            $limits = (array) config('plugins.zip', []);
            $maxEntries = (int) ($limits['max_entry_count'] ?? 5000);
            $maxTotal = (int) ($limits['max_total_uncompressed_bytes'] ?? 50 * 1024 * 1024);
            $maxPerEntry = (int) ($limits['max_entry_uncompressed_bytes'] ?? 10 * 1024 * 1024);
            $maxRatio = (int) ($limits['max_compression_ratio'] ?? 100);
            $maxManifestBytes = (int) ($limits['max_manifest_bytes'] ?? 256 * 1024);

            $entryCount = $zip->numFiles;
            if ($entryCount > $maxEntries) {
                throw PluginPackageRejectedException::reason("Archive contains {$entryCount} entries, exceeding the limit of {$maxEntries}.");
            }

            // Fast pre-filter pass on declared metadata only (never authoritative).
            for ($i = 0; $i < $entryCount; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw PluginPackageRejectedException::reason('Unable to read archive entry metadata.');
                }
                $declaredSize = (int) $stat['size'];
                $compSize = max(1, (int) $stat['comp_size']);
                if ($declaredSize > $maxPerEntry) {
                    throw PluginPackageRejectedException::reason("Entry [{$stat['name']}] declares {$declaredSize} bytes, exceeding the per-entry limit.");
                }
                if (($declaredSize / $compSize) > $maxRatio) {
                    throw PluginPackageRejectedException::reason("Entry [{$stat['name']}] has a suspicious compression ratio.");
                }
            }

            // plugin.json is read alone, first, with its own byte cap.
            $manifestJson = $this->readEntryBoundedByName($zip, 'plugin.json', $maxManifestBytes);
            if ($manifestJson === null) {
                throw PluginPackageRejectedException::reason('Archive does not contain a plugin.json manifest at its root.');
            }
            $manifestSha256 = hash('sha256', $manifestJson);
            $manifest = PluginManifest::fromJson($manifestJson);

            // plugin.sig (optional) is also read alone, capped.
            $integrity = null;
            $sigJson = $this->readEntryBoundedByName($zip, 'plugin.sig', $maxManifestBytes);
            if ($sigJson !== null) {
                $sigData = json_decode($sigJson, associative: true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($sigData)) {
                    throw PluginPackageRejectedException::reason('plugin.sig is not valid JSON.');
                }
                /** @var array<string, mixed> $sigData */
                $integrity = PluginPackageIntegrity::fromArray($sigData);

                if ($integrity->manifestSha256 !== $manifestSha256) {
                    throw PluginPackageRejectedException::reason('plugin.sig manifest_sha256 does not match the actual plugin.json bytes.');
                }
            }

            $stagingPath = rtrim($stagingBasePath, '/').'/'.(string) Str::uuid();
            if (! mkdir($stagingPath, 0755, true) && ! is_dir($stagingPath)) {
                throw PluginPackageRejectedException::reason('Unable to create staging directory.');
            }

            $extractedFiles = [];
            $totalBytes = 0;

            for ($i = 0; $i < $entryCount; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    throw PluginPackageRejectedException::reason('Unable to read archive entry name.');
                }

                if (str_ends_with($name, '/')) {
                    // Directory entry — validate path safety, then continue (no bytes to copy).
                    $this->assertSafeRelativePath($name);

                    continue;
                }

                if ($name === 'plugin.sig') {
                    continue; // already read; never itself part of the signed file list.
                }

                $this->assertSafeRelativePath($name);
                $this->assertNotSymlink($zip, $i);

                $destination = $stagingPath.'/'.$name;
                $destinationDir = dirname($destination);
                if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0755, true) && ! is_dir($destinationDir)) {
                    throw PluginPackageRejectedException::reason("Unable to create directory for entry [{$name}].");
                }

                $bytesWritten = $this->streamCopyEntry($zip, $i, $destination, $maxPerEntry);
                $totalBytes += $bytesWritten;
                if ($totalBytes > $maxTotal) {
                    throw PluginPackageRejectedException::reason("Archive exceeds the total uncompressed size limit of {$maxTotal} bytes.");
                }

                $fileHash = hash_file('sha256', $destination);
                if ($fileHash === false) {
                    throw PluginPackageRejectedException::reason("Unable to hash extracted file for entry [{$name}].");
                }
                $extractedFiles[$name] = $fileHash;
            }

            if ($integrity !== null) {
                $this->verifyFileListAgainstIntegrity($extractedFiles, $integrity->files);
            }

            $signatureValid = false;
            $trustedKeyId = null;
            if ($integrity !== null) {
                $result = $this->signatureVerifier->verify($integrity);
                $signatureValid = $result['valid'];
                $trustedKeyId = $result['trustedKeyId'];

                if (! $signatureValid) {
                    throw PluginPackageRejectedException::reason('plugin.sig signature is present but does not verify against any trusted publisher key.');
                }
            }

            return new PluginStagingResult(
                stagingPath: $stagingPath,
                manifest: $manifest,
                integrity: $integrity,
                signatureValid: $signatureValid,
                trustedKeyId: $trustedKeyId,
            );
        } finally {
            $zip->close();
        }
    }

    private function readEntryBoundedByName(ZipArchive $zip, string $name, int $maxBytes): ?string
    {
        $index = $zip->locateName($name);
        if ($index === false) {
            return null;
        }

        $stat = $zip->statIndex($index);
        if ($stat !== false && (int) $stat['size'] > $maxBytes) {
            throw PluginPackageRejectedException::reason("Entry [{$name}] exceeds the manifest size limit.");
        }

        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw PluginPackageRejectedException::reason("Unable to open entry [{$name}] for reading.");
        }

        $contents = '';
        $bytesRead = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, self::READ_CHUNK_BYTES);
            if ($chunk === false) {
                break;
            }
            $bytesRead += strlen($chunk);
            if ($bytesRead > $maxBytes) {
                fclose($stream);
                throw PluginPackageRejectedException::reason("Entry [{$name}] exceeds the manifest size limit during read.");
            }
            $contents .= $chunk;
        }
        fclose($stream);

        return $contents;
    }

    private function streamCopyEntry(ZipArchive $zip, int $index, string $destination, int $maxBytes): int
    {
        $name = $zip->getNameIndex($index);
        $stream = $zip->getStream((string) $name);
        if ($stream === false) {
            throw PluginPackageRejectedException::reason("Unable to open entry [{$name}] for extraction.");
        }

        $out = fopen($destination, 'wb');
        if ($out === false) {
            fclose($stream);
            throw PluginPackageRejectedException::reason("Unable to write staged file for entry [{$name}].");
        }

        $bytesWritten = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, self::READ_CHUNK_BYTES);
            if ($chunk === false) {
                break;
            }
            $bytesWritten += strlen($chunk);
            if ($bytesWritten > $maxBytes) {
                fclose($stream);
                fclose($out);
                @unlink($destination);
                throw PluginPackageRejectedException::reason("Entry [{$name}] exceeds the per-entry uncompressed size limit during extraction.");
            }
            fwrite($out, $chunk);
        }

        fclose($stream);
        fclose($out);

        return $bytesWritten;
    }

    private function assertSafeRelativePath(string $name): void
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0")) {
            throw PluginPackageRejectedException::reason("Archive entry [{$name}] has an unsafe path.");
        }

        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw PluginPackageRejectedException::reason("Archive entry [{$name}] attempts path traversal.");
            }
        }
    }

    private function assertNotSymlink(ZipArchive $zip, int $index): void
    {
        // ZipArchive::statIndex() does not reliably expose external_attr on
        // all builds — getExternalAttributesIndex() is the documented,
        // correct API for reading the Unix mode bits a symlink is encoded in.
        $opsys = 0;
        $attr = 0;
        $ok = $zip->getExternalAttributesIndex($index, $opsys, $attr);
        if (! $ok) {
            return;
        }

        $unixMode = ((int) $attr >> 16) & 0xF000;

        if ($unixMode === self::S_IFLNK) {
            $name = $zip->getNameIndex($index) ?: "index {$index}";
            throw PluginPackageRejectedException::reason("Archive entry [{$name}] is a symlink, which is not permitted.");
        }
    }

    /**
     * @param  array<string, string>  $extracted
     * @param  array<string, string>  $declared
     */
    private function verifyFileListAgainstIntegrity(array $extracted, array $declared): void
    {
        $extraFiles = array_diff(array_keys($extracted), array_keys($declared));
        if ($extraFiles !== []) {
            throw PluginPackageRejectedException::reason('Archive contains files not listed in plugin.sig: '.implode(', ', $extraFiles));
        }

        $missingFiles = array_diff(array_keys($declared), array_keys($extracted));
        if ($missingFiles !== []) {
            throw PluginPackageRejectedException::reason('plugin.sig lists files missing from the archive: '.implode(', ', $missingFiles));
        }

        foreach ($declared as $path => $expectedHash) {
            if (! hash_equals($expectedHash, $extracted[$path])) {
                throw PluginPackageRejectedException::reason("File [{$path}] does not match its signed hash.");
            }
        }
    }
}
