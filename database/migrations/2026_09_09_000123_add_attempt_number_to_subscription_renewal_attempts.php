<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE-21 acceptance-check fix: a durable, monotonically-incrementing
 * attempt counter for dunning retries. Without it, a re-claimed
 * SubscriptionRenewalAttempt row (the SAME row is reused across retries
 * per Owner Delta §11 — never a second row for one billing period) had no
 * way to derive a genuinely distinct provider_idempotency_key per real
 * retry attempt, since counting existing rows for the period always
 * yields 1. This column is the fix — incremented once per genuine retry,
 * never per replayed/duplicate call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_renewal_attempts', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_number')->default(1)->after('billing_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_renewal_attempts', function (Blueprint $table): void {
            $table->dropColumn('attempt_number');
        });
    }
};
