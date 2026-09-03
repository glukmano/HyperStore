<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Services;

use App\Core\SuperAdmin\Contracts\OfficialExtensionGovernanceServiceInterface;
use App\Core\SuperAdmin\Models\OfficialExtension;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class OfficialExtensionGovernanceService implements OfficialExtensionGovernanceServiceInterface
{
    public function registerExtension(
        string $slug,
        string $name,
        string $publisher,
        string $category,
        array $compatibility = []
    ): OfficialExtension {
        return OfficialExtension::create([
            'slug' => $slug,
            'name' => $name,
            'publisher_name' => $publisher,
            'category' => $category,
            'status' => 'draft',
            'compatibility_metadata' => $compatibility,
            'visibility' => 'public',
        ]);
    }

    public function approveExtension(int $extensionId, string $approvedVersion): OfficialExtension
    {
        return DB::transaction(function () use ($extensionId, $approvedVersion): OfficialExtension {
            /** @var OfficialExtension $extension */
            $extension = OfficialExtension::where('id', $extensionId)->lockForUpdate()->findOrFail($extensionId);

            if ($extension->status !== 'draft') {
                throw new InvalidArgumentException("Only draft extensions can be approved; current status: [{$extension->status}].");
            }

            $extension->status = 'approved';
            $extension->approved_version = $approvedVersion;
            $extension->approved_at = CarbonImmutable::now();
            $extension->save();

            return $extension;
        });
    }

    public function publishExtension(int $extensionId): OfficialExtension
    {
        return DB::transaction(function () use ($extensionId): OfficialExtension {
            /** @var OfficialExtension $extension */
            $extension = OfficialExtension::where('id', $extensionId)->lockForUpdate()->findOrFail($extensionId);

            if (! in_array($extension->status, ['approved', 'suspended'], true)) {
                throw new InvalidArgumentException("Only approved or suspended extensions can be published; current status: [{$extension->status}].");
            }

            $extension->status = 'published';
            if ($extension->published_at === null) {
                $extension->published_at = CarbonImmutable::now();
            }
            $extension->save();

            return $extension;
        });
    }

    public function suspendExtension(int $extensionId): OfficialExtension
    {
        return DB::transaction(function () use ($extensionId): OfficialExtension {
            /** @var OfficialExtension $extension */
            $extension = OfficialExtension::where('id', $extensionId)->lockForUpdate()->findOrFail($extensionId);

            if ($extension->status !== 'published') {
                throw new InvalidArgumentException("Only published extensions can be suspended; current status: [{$extension->status}].");
            }

            $extension->status = 'suspended';
            $extension->save();

            return $extension;
        });
    }
}
