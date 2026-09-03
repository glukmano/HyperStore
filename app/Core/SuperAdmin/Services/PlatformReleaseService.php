<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\PlatformReleaseServiceInterface;
use App\Core\SuperAdmin\Models\PlatformRelease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class PlatformReleaseService implements PlatformReleaseServiceInterface
{
    public function createRelease(string $version, string $channel, string $notes, array $compatibility = []): PlatformRelease
    {
        return PlatformRelease::create([
            'version' => $version,
            'channel' => $channel,
            'status' => 'draft',
            'release_notes' => $notes,
            'compatibility_metadata' => $compatibility,
        ]);
    }

    public function publishRelease(int $releaseId): PlatformRelease
    {
        return DB::transaction(function () use ($releaseId): PlatformRelease {
            /** @var PlatformRelease $release */
            $release = PlatformRelease::where('id', $releaseId)->lockForUpdate()->findOrFail($releaseId);

            if ($release->status !== 'draft') {
                throw new InvalidArgumentException("Only draft releases can be published; current status: [{$release->status}].");
            }

            $release->status = 'published';
            $release->published_at = CarbonImmutable::now();
            $release->save();

            return $release;
        });
    }

    public function withdrawRelease(int $releaseId): PlatformRelease
    {
        return DB::transaction(function () use ($releaseId): PlatformRelease {
            /** @var PlatformRelease $release */
            $release = PlatformRelease::where('id', $releaseId)->lockForUpdate()->findOrFail($releaseId);

            if ($release->status !== 'published') {
                throw new InvalidArgumentException("Only published releases can be withdrawn; current status: [{$release->status}].");
            }

            $release->status = 'withdrawn';
            $release->withdrawn_at = CarbonImmutable::now();
            $release->save();

            return $release;
        });
    }
}
