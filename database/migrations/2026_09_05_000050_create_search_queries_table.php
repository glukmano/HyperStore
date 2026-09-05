<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('normalized_query', 255);
            $table->string('raw_query', 500);
            $table->unsignedInteger('result_count')->default(0);
            $table->string('locale', 10);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'normalized_query']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'result_count']);
        });

        // One query execution can produce multiple result clicks (a shopper
        // opening several results in new tabs) — a single clicked_product_id
        // column on search_queries could only ever record one, so clicks are
        // a proper child table with their own query_id FK.
        Schema::create('search_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_query_id')->constrained('search_queries')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('result_position')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'product_id']);
            $table->index('search_query_id');
        });

        // ── Search synonyms / merchandising — PostgreSQL-authoritative ────────
        // Meilisearch's own index settings are a disposable, rebuildable
        // projection (Master §26 "search index is not source of truth"); a
        // full reindex must be able to restore synonyms/merchandising from
        // here, never from whatever happens to still be in the index.
        Schema::create('search_synonyms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('term', 100);
            $table->jsonb('synonyms');
            $table->timestamps();

            $table->unique(['tenant_id', 'locale', 'term']);
        });

        Schema::create('search_merchandising_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('query_term', 255)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('pin_position')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'store_id', 'query_term']);
            $table->index(['tenant_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_merchandising_rules');
        Schema::dropIfExists('search_synonyms');
        Schema::dropIfExists('search_clicks');
        Schema::dropIfExists('search_queries');
    }
};
