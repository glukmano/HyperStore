<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Price Books
        Schema::create('price_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('market_id')->nullable()->constrained('markets')->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->unsignedBigInteger('customer_group_id')->nullable()->index();

            $table->string('name');
            $table->string('code', 100);
            $table->string('currency', 3);
            $table->integer('priority')->default(0)->index();
            $table->boolean('is_default')->default(false);
            $table->string('status', 30)->default('active')->index();

            $table->timestamp('valid_from')->nullable()->index();
            $table->timestamp('valid_until')->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // 2. Prices
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('price_book_id')->constrained('price_books')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();

            $table->unsignedBigInteger('amount_minor'); // in minor units
            $table->unsignedBigInteger('compare_at_minor')->nullable();
            $table->unsignedBigInteger('cost_minor')->nullable(); // Protected cost field
            $table->string('currency', 3);
            $table->string('status', 30)->default('active')->index();

            $table->timestamp('valid_from')->nullable()->index();
            $table->timestamp('valid_until')->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['price_book_id', 'product_id', 'product_variant_id']);
        });

        // 3. Tier Prices (Quantity volume breaks)
        Schema::create('tier_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_id')->constrained('prices')->cascadeOnDelete();
            $table->unsignedInteger('min_quantity');
            $table->unsignedInteger('max_quantity')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->unique(['price_id', 'min_quantity']);
        });

        // 4. Exchange Rates
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('base_currency', 3);
            $table->string('target_currency', 3);
            $table->decimal('rate', 16, 8); // 8 decimal places for precise exchange conversion
            $table->string('source', 50)->default('manual');
            $table->boolean('is_stale')->default(false);
            $table->timestamp('effective_at')->useCurrent()->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'base_currency', 'target_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('tier_prices');
        Schema::dropIfExists('prices');
        Schema::dropIfExists('price_books');
    }
};
