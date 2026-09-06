<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-21 Gift Cards: a genuinely distinct Store Value instrument from the
 * base model onward (Owner Delta §15) — modules/GiftCards is thin and
 * delegates all balance/ledger movement to modules/Wallet's
 * StoreValueServiceInterface. The plaintext code is NEVER persisted — only
 * a secure hash (for lookup) and the last 4 characters (for operator-safe
 * display) are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('gift_cards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->string('code_last4', 4);
            $table->string('issuer_scope', 20)->default('tenant');
            $table->string('currency', 3);
            $table->bigInteger('initial_value_minor');
            $table->string('status', 20)->default('unactivated');
            $table->unsignedBigInteger('store_value_account_id')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE gift_cards ADD CONSTRAINT chk_gift_cards_status CHECK (status IN ('unactivated', 'active', 'redeemed', 'deactivated'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};
