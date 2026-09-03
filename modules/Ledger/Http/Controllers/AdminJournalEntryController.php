<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Ledger\Contracts\JournalReversalServiceInterface;
use Modules\Ledger\Models\JournalEntry;
use Modules\Ledger\Models\JournalLine;

class AdminJournalEntryController extends Controller
{
    public function __construct(
        private readonly ContextManager $contextManager,
        private readonly JournalReversalServiceInterface $reversalService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, ['ledger.journals.view', 'admin']);
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        $journals = JournalEntry::where('tenant_id', $tenantId)
            ->with(['lines.account'])
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $journals->map(fn (JournalEntry $j) => $this->formatJournal($j)),
        ]);
    }

    public function show(string $uuid, Request $request): JsonResponse
    {
        $this->authorizePermission($request, ['ledger.journals.view', 'admin']);
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        /** @var JournalEntry|null $journal */
        $journal = JournalEntry::where('tenant_id', $tenantId)
            ->where('uuid', $uuid)
            ->with(['lines.account', 'reversalEntry'])
            ->first();

        if ($journal === null) {
            abort(404, 'Journal entry not found.');
        }

        return response()->json([
            'data' => $this->formatJournal($journal),
        ]);
    }

    public function reverse(string $uuid, Request $request): JsonResponse
    {
        $this->authorizePermission($request, ['ledger.journals.reverse', 'admin']);
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $reversalJournal = $this->reversalService->reverse($tenantId, $uuid, (string) $validated['reason']);

        return response()->json([
            'message' => 'Journal entry reversed successfully.',
            'data' => $this->formatJournal($reversalJournal->load(['lines.account'])),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatJournal(JournalEntry $j): array
    {
        return [
            'uuid' => $j->uuid,
            'source_module' => $j->source_module,
            'source_type' => $j->source_type,
            'source_uuid' => $j->source_uuid,
            'posting_type' => $j->posting_type,
            'currency' => $j->currency,
            'description' => $j->description,
            'effective_at' => $j->effective_at->toIso8601String(),
            'posted_at' => $j->posted_at->toIso8601String(),
            'is_reversed' => $j->isReversed(),
            'lines' => $j->lines->map(fn (JournalLine $l) => [
                'uuid' => $l->uuid,
                'account_uuid' => $l->account->uuid,
                'account_code' => $l->account->code,
                'direction' => $l->direction,
                'amount_minor' => $l->amount_minor,
                'currency' => $l->currency,
                'description' => $l->description,
            ])->all(),
        ];
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
