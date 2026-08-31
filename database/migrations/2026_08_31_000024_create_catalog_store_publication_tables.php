<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Multi-Store Product Publication ────────────────────────────────────
        Schema::create('product_store_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->enum('status', ['published', 'hidden', 'draft'])->default('draft');
            $table->enum('visibility', ['visible', 'catalog_only', 'search_only', 'hidden'])->default('visible');
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'store_id'], 'psl_prod_store_unique');
            $table->index(['store_id', 'status', 'visibility']);
        });

        Schema::create('product_store_listing_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_store_listing_id')->constrained('product_store_listings', 'id', 'pslt_listing_id_fk')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('slug', 255);
            $table->string('name', 255)->nullable();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->timestamps();

            $table->unique(['product_store_listing_id', 'locale'], 'pslt_listing_locale_unique');
            $table->index(['locale', 'slug']);
        });

        Schema::create('product_store_markets', function (Blueprint $table): void {
            $table->foreignId('product_store_listing_id')->constrained('product_store_listings', 'id', 'psm_listing_id_fk')->cascadeOnDelete();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->primary(['product_store_listing_id', 'market_id'], 'psm_listing_market_pk');
        });

        Schema::create('product_store_channels', function (Blueprint $table): void {
            $table->foreignId('product_store_listing_id')->constrained('product_store_listings', 'id', 'psc_listing_id_fk')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->primary(['product_store_listing_id', 'channel_id'], 'psc_listing_channel_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_store_channels');
        Schema::dropIfExists('product_store_markets');
        Schema::dropIfExists('product_store_listing_translations');
        Schema::dropIfExists('product_store_listings');
    }
};
