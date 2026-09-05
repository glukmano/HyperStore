<?php

declare(strict_types=1);

namespace Modules\Promotions\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Promotions\Models\LoyaltyProgram;
use Modules\Promotions\Models\LoyaltyProgramCurrencyRule;

/**
 * Phase-19 Final Completion Delta §1: the operable Control Center screen for
 * Loyalty configuration. Introduces no new economic semantics — every field
 * here maps 1:1 to a column LoyaltyService already reads (pending_hold_days,
 * points_expire_after_days, referral_reward_points, and per-currency earn/
 * redemption rates on LoyaltyProgramCurrencyRule).
 */
class LoyaltyProgramManager extends Component
{
    public string $name = '';

    public bool $isActive = true;

    public int $pendingHoldDays = 0;

    public ?int $pointsExpireAfterDays = null;

    public int $referralRewardPoints = 500;

    public string $ruleCurrency = '';

    public int $ruleMinorUnitsPerPoint = 100;

    public int $ruleRedemptionValueMinor = 1;

    public function mount(): void
    {
        $this->assertCan('loyalty.view');

        $program = $this->activeProgram();
        if ($program !== null) {
            $this->name = $program->name;
            $this->isActive = $program->is_active;
            $this->pendingHoldDays = $program->pending_hold_days;
            $this->pointsExpireAfterDays = $program->points_expire_after_days;
            $this->referralRewardPoints = $program->referral_reward_points;
        }
    }

    private function assertCan(string $permission): void
    {
        if (! auth()->user()?->can($permission) && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    private function tenantId(): int
    {
        return (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
    }

    private function activeProgram(): ?LoyaltyProgram
    {
        return LoyaltyProgram::where('tenant_id', $this->tenantId())->where('is_active', true)->first()
            ?? LoyaltyProgram::where('tenant_id', $this->tenantId())->orderByDesc('id')->first();
    }

    public function saveProgram(): void
    {
        $this->assertCan('loyalty.manage');

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'pendingHoldDays' => ['required', 'integer', 'min:0'],
            'pointsExpireAfterDays' => ['nullable', 'integer', 'min:1'],
            'referralRewardPoints' => ['required', 'integer', 'min:0'],
        ]);

        $tenantId = $this->tenantId();
        $program = $this->activeProgram();

        // At most one active program per Tenant (DB-enforced partial unique
        // index) — deactivate any other row before activating this one.
        if ($this->isActive) {
            $excludeId = $program?->id;
            LoyaltyProgram::where('tenant_id', $tenantId)
                ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
                ->update(['is_active' => false]);
        }

        if ($program === null) {
            LoyaltyProgram::create([
                'tenant_id' => $tenantId,
                'name' => $this->name,
                'is_active' => $this->isActive,
                'pending_hold_days' => $this->pendingHoldDays,
                'points_expire_after_days' => $this->pointsExpireAfterDays,
                'referral_reward_points' => $this->referralRewardPoints,
            ]);
        } else {
            $program->update([
                'name' => $this->name,
                'is_active' => $this->isActive,
                'pending_hold_days' => $this->pendingHoldDays,
                'points_expire_after_days' => $this->pointsExpireAfterDays,
                'referral_reward_points' => $this->referralRewardPoints,
            ]);
        }

        session()->flash('success', 'Loyalty program settings saved.');
    }

    public function saveCurrencyRule(): void
    {
        $this->assertCan('loyalty.manage');

        $this->validate([
            'ruleCurrency' => ['required', 'string', 'size:3'],
            'ruleMinorUnitsPerPoint' => ['required', 'integer', 'min:1'],
            'ruleRedemptionValueMinor' => ['required', 'integer', 'min:1'],
        ]);

        $program = $this->activeProgram();
        if ($program === null) {
            session()->flash('error', 'Save the Loyalty program before adding currency rules.');

            return;
        }

        LoyaltyProgramCurrencyRule::updateOrCreate(
            [
                'tenant_id' => $this->tenantId(),
                'loyalty_program_id' => $program->id,
                'currency' => strtoupper($this->ruleCurrency),
            ],
            [
                'minor_units_per_point' => $this->ruleMinorUnitsPerPoint,
                'point_redemption_value_minor' => $this->ruleRedemptionValueMinor,
                'is_active' => true,
            ]
        );

        $this->reset(['ruleCurrency', 'ruleMinorUnitsPerPoint', 'ruleRedemptionValueMinor']);
        $this->ruleMinorUnitsPerPoint = 100;
        $this->ruleRedemptionValueMinor = 1;
        session()->flash('success', 'Currency rule saved.');
    }

    public function toggleRule(int $ruleId): void
    {
        $this->assertCan('loyalty.manage');

        $rule = LoyaltyProgramCurrencyRule::where('tenant_id', $this->tenantId())->findOrFail($ruleId);
        $rule->is_active = ! $rule->is_active;
        $rule->save();
    }

    public function render(): View
    {
        $program = $this->activeProgram();
        $rules = $program !== null
            ? LoyaltyProgramCurrencyRule::where('tenant_id', $this->tenantId())
                ->where('loyalty_program_id', $program->id)
                ->orderBy('currency')
                ->get()
            : collect();

        return view('promotions::livewire.loyalty-program-manager', [
            'program' => $program,
            'rules' => $rules,
        ]);
    }
}
