<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Promotions
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 100);
            $table->text('description')->nullable();
            $table->integer('priority')->default(0)->index();
            $table->boolean('is_exclusive')->default(false);
            $table->boolean('is_stackable')->default(true);
            $table->boolean('stop_further_rules')->default(false);
            $table->string('status', 30)->default('active')->index();

            $table->timestamp('valid_from')->nullable()->index();
            $table->timestamp('valid_until')->nullable()->index();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // 2. Promotion Conditions
        Schema::create('promotion_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('condition_type', 100); // e.g. product, category, min_cart_amount, customer_group
            $table->jsonb('parameters'); // validated typed payload
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['promotion_id', 'condition_type']);
        });

        // 3. Promotion Actions (Discounts / Rewards)
        Schema::create('promotion_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('action_type', 100); // e.g. percentage_discount, fixed_discount, buy_x_get_y, free_shipping
            $table->jsonb('parameters'); // validated typed payload
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['promotion_id', 'action_type']);
        });

        // 4. Coupons
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('valid_from')->nullable()->index();
            $table->timestamp('valid_until')->nullable()->index();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('promotion_actions');
        Schema::dropIfExists('promotion_conditions');
        Schema::dropIfExists('promotions');
    }
};
