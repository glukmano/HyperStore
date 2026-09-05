<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-19 Final Completion Delta §5: the Customer-referral reward amount
 * was hardcoded (500 points) inside CustomerReferralService — a magic
 * business rule. This column makes it an explicit, Tenant-scoped
 * configuration value on the same LoyaltyProgram row that already governs
 * every other Loyalty economic policy (hold days, expiry), rather than
 * inventing a new configuration boundary. Default preserves prior behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table): void {
            $table->unsignedInteger('referral_reward_points')->default(500)->after('points_expire_after_days');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table): void {
            $table->dropColumn('referral_reward_points');
        });
    }
};
