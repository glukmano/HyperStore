<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_token_hash', 64)->nullable()->index();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->string('locale', 10)->default('en');
            $table->string('status', 20)->default('active'); // active, converted, abandoned, expired, locked
            $table->string('coupon_code', 100)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // Partial unique index for active customer carts (Tenant + User + Store + Market + Channel)
        DB::statement("
            CREATE UNIQUE INDEX unique_active_user_cart 
            ON carts (tenant_id, user_id, store_id, market_id, channel_id) 
            WHERE status = 'active' AND user_id IS NOT NULL;
        ");

        // Partial unique index for active guest carts (Tenant + Guest Token Hash + Store + Market + Channel)
        DB::statement("
            CREATE UNIQUE INDEX unique_active_guest_cart 
            ON carts (tenant_id, guest_token_hash, store_id, market_id, channel_id) 
            WHERE status = 'active' AND guest_token_hash IS NOT NULL;
        ");

        Schema::create('cart_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 20, 8); // NUMERIC(20,8) technical upper bound
            $table->unsignedBigInteger('display_unit_price_minor')->nullable();
            $table->string('display_currency', 3)->nullable();
            $table->string('signature', 64);
            $table->jsonb('options')->nullable();
            $table->jsonb('customizations')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['cart_id', 'signature'], 'unique_cart_line_signature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_lines');
        Schema::dropIfExists('carts');
    }
};
