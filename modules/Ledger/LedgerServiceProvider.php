<?php

declare(strict_types=1);

namespace Modules\Ledger;

use App\Core\Modular\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Ledger\Commands\AuditUnpostedPaymentTransactionsCommand;
use Modules\Ledger\Commands\ReplayUnpostedPaymentTransactionsCommand;
use Modules\Ledger\Contracts\AccountBalanceQueryInterface;
use Modules\Ledger\Contracts\JournalReversalServiceInterface;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Contracts\LedgerConcurrencyBarrierInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\Listeners\PaymentEventAdapter;
use Modules\Ledger\Policies\PaymentMovementEligibilityPolicy;
use Modules\Ledger\Services\AccountBalanceQueryService;
use Modules\Ledger\Services\JournalReversalService;
use Modules\Ledger\Services\LedgerAccountRegistry;
use Modules\Ledger\Services\LedgerPostingService;
use Modules\Ledger\Services\NoOpLedgerConcurrencyBarrier;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;

class LedgerServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(LedgerConcurrencyBarrierInterface::class, NoOpLedgerConcurrencyBarrier::class);
        $this->app->singleton(LedgerAccountRegistryInterface::class, LedgerAccountRegistry::class);
        $this->app->singleton(LedgerPostingServiceInterface::class, LedgerPostingService::class);
        $this->app->singleton(JournalReversalServiceInterface::class, JournalReversalService::class);
        $this->app->singleton(AccountBalanceQueryInterface::class, AccountBalanceQueryService::class);
        $this->app->singleton(PaymentMovementEligibilityPolicy::class, PaymentMovementEligibilityPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                AuditUnpostedPaymentTransactionsCommand::class,
                ReplayUnpostedPaymentTransactionsCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $routesPath = __DIR__.'/Routes/api.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        // Register synchronous event adapter for after-commit payment events
        Event::listen(PaymentCaptured::class, [PaymentEventAdapter::class, 'handle']);
        Event::listen(PaymentPartiallyRefunded::class, [PaymentEventAdapter::class, 'handle']);
        Event::listen(PaymentRefunded::class, [PaymentEventAdapter::class, 'handle']);
    }
}
