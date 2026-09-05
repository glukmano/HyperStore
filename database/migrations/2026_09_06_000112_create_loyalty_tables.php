<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-19 Owner Delta: Loyalty points program. Points are non-cash,
 * non-withdrawable, discount-entitlement only (Owner Delta correction §22) —
 * this migration deliberately never touches modules/Ledger. Earn/redemption
 * rates are per-currency (correction §10); the points balance itself is a
 * currency-neutral integer count. A dedicated lock-anchor row exists purely
 * to serialize concurrent redemption (correction §12).
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('loyalty_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedInteger('pending_hold_days')->default(0);
            $table->unsignedInteger('points_expire_after_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
        if ($isPgsql) {
            DB::statement('CREATE UNIQUE INDEX uq_loyalty_programs_one_active ON loyalty_programs (tenant_id) WHERE is_active = true');
        }

        Schema::create('loyalty_program_currency_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->unsignedInteger('minor_units_per_point');
            $table->unsignedInteger('point_redemption_value_minor');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'loyalty_program_id', 'currency']);
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE loyalty_program_currency_rules ADD CONSTRAINT chk_loyalty_rules_positive CHECK (minor_units_per_point > 0 AND point_redemption_value_minor > 0)');
        }

        Schema::create('loyalty_point_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->foreignId('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->string('entry_type', 32);
            $table->integer('points');
            $table->string('redemption_currency', 3)->nullable();
            $table->integer('redemption_value_minor')->nullable();
            $table->string('source_type', 64);
            $table->string('source_uuid', 64);
            $table->string('availability_status', 32)->default('available');
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'source_type', 'source_uuid', 'entry_type'], 'uq_loyalty_entries_unique_movement');
            $table->index(['tenant_id', 'customer_profile_id', 'availability_status']);
            $table->index(['tenant_id', 'availability_status', 'available_at']);
            $table->index(['tenant_id', 'expires_at']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE loyalty_point_entries ADD CONSTRAINT chk_loyalty_entry_type CHECK (entry_type IN ('earned', 'redeemed', 'expired', 'manual_adjustment_credit', 'manual_adjustment_debit'))");
            DB::statement("ALTER TABLE loyalty_point_entries ADD CONSTRAINT chk_loyalty_avail_status CHECK (availability_status IN ('pending', 'available', 'held'))");
        }

        // Owner Delta correction §12: a dedicated lock-anchor row, created
        // lazily, whose sole purpose is to serialize concurrent redemption
        // via SELECT ... FOR UPDATE — never itself an economic record.
        Schema::create('loyalty_account_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->foreignId('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_profile_id', 'loyalty_program_id'], 'uq_loyalty_lock_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_account_locks');
        Schema::dropIfExists('loyalty_point_entries');
        Schema::dropIfExists('loyalty_program_currency_rules');
        Schema::dropIfExists('loyalty_programs');
    }
};
