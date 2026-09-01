<?php

declare(strict_types=1);

namespace Modules\Cart\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Cart\Models\Cart;

class CleanupExpiredCartsCommand extends Command
{
    protected $signature = 'hyper:cart:cleanup-expired';

    protected $description = 'Expire abandoned or timed-out carts';

    public function handle(): int
    {
        $count = Cart::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', Carbon::now())
            ->update(['status' => 'expired']);

        $this->info("Expired [{$count}] stale cart(s).");

        return 0;
    }
}
