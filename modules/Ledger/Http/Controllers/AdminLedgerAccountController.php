<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Ledger\Contracts\AccountBalanceQueryInterface;
use Modules\Ledger\Models\LedgerAccount;

class AdminLedgerAccountController extends Controller
{
    public function __construct(
        private readonly ContextManager $contextManager,
        private readonly AccountBalanceQueryInterface $balanceQuery
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, ['ledger.accounts.view', 'ledger.accounts.manage', 'admin']);
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        $accounts = LedgerAccount::where('tenant_id', $tenantId)->get();

        return response()->json([
            'data' => $accounts->map(fn (LedgerAccount $account) => [
                'uuid' => $account->uuid,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'normal_balance' => $account->normal_balance,
                'role' => $account->role,
                'currency' => $account->currency,
                'is_system' => $account->is_system,
                'status' => $account->status,
                'description' => $account->description,
            ]),
        ]);
    }

    public function show(string $uuid, Request $request): JsonResponse
    {
        $this->authorizePermission($request, ['ledger.accounts.view', 'ledger.accounts.manage', 'admin']);
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        /** @var LedgerAccount|null $account */
        $account = LedgerAccount::where('tenant_id', $tenantId)
            ->where('uuid', $uuid)
            ->first();

        if ($account === null) {
            abort(404, 'Ledger account not found.');
        }

        $balances = $this->balanceQuery->getBalances($tenantId, (int) $account->id);

        return response()->json([
            'data' => [
                'uuid' => $account->uuid,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'normal_balance' => $account->normal_balance,
                'role' => $account->role,
                'currency' => $account->currency,
                'is_system' => $account->is_system,
                'status' => $account->status,
                'description' => $account->description,
                'balances' => array_map(fn ($b) => [
                    'currency' => $b->currency,
                    'balance_minor' => $b->balanceMinor,
                    'debit_total_minor' => $b->debitTotalMinor,
                    'credit_total_minor' => $b->creditTotalMinor,
                ], $balances),
            ],
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function authorizePermission(Request $request, array $permissions): void
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return;
            }
        }

        abort(403, 'Unauthorized. Missing required ledger permission.');
    }
}
