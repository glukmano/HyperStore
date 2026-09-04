<?php

declare(strict_types=1);

namespace Modules\Seo\Jobs;

use App\Core\Stores\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Seo\Services\SitemapGenerator;

final class GenerateSitemapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $storeId,
    ) {}

    public function handle(SitemapGenerator $generator): void
    {
        $store = Store::query()->find($this->storeId);

        if ($store !== null) {
            $generator->generateAndStore($store);
        }
    }
}
