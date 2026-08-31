<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('transfer_number', 100);
            $table->foreignId('source_inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->foreignId('destination_inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->foreignId('source_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('destination_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('status', 50)->default('draft'); // draft, requested, in_transit, received, cancelled
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'transfer_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('inventory_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_transfer_id')->constrained('inventory_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('requested_quantity', 14, 4);
            $table->decimal('dispatched_quantity', 14, 4)->default(0);
            $table->decimal('received_quantity', 14, 4)->default(0);
            $table->timestamps();

            $table->index(['inventory_transfer_id', 'product_id', 'product_variant_id'], 'inv_tr_items_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
        Schema::dropIfExists('inventory_transfers');
    }
};
