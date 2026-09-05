<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-19 Owner Delta: Customer referral (peer-to-peer, non-monetary reward)
 * — deliberately separate from the Affiliate bounded context (ADR-0143).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_referral_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->string('code', 32);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'customer_profile_id']);
        });

        Schema::create('customer_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('referrer_customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->foreignId('referred_customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->foreignId('customer_referral_code_id')->constrained('customer_referral_codes')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->foreignId('qualifying_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestampTz('rewarded_at')->nullable();
            $table->timestamps();

            // Owner Delta correction §13: Tenant-scoped uniqueness — one
            // referrer per referred Customer within a Tenant, ever.
            $table->unique(['tenant_id', 'referred_customer_profile_id']);
            $table->index(['tenant_id', 'referrer_customer_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_referrals');
        Schema::dropIfExists('customer_referral_codes');
    }
};
