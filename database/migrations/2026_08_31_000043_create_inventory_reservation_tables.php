<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reservation_key', 100);
            $table->string('status', 50)->default('active'); // active, committed, released, expired
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reservation_key']);
            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        Schema::create('inventory_reservation_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_reservation_id')->constrained('inventory_reservations')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->foreignId('inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->timestamps();

            $table->index(['inventory_reservation_id', 'stock_item_id'], 'inv_res_alloc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservation_allocations');
        Schema::dropIfExists('inventory_reservations');
    }
};
