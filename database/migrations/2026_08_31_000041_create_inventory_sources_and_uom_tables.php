<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique(); // piece, kg, g, meter, cm, liter
            $table->string('name', 100);
            $table->string('symbol', 20);
            $table->unsignedSmallInteger('scale')->default(4);
            $table->string('status', 50)->default('active');
            $table->timestamps();
        });

        Schema::create('inventory_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('source_type', 50)->default('warehouse'); // warehouse, vendor, supplier, 3pl, dropship, virtual
            $table->string('code', 100);
            $table->string('name', 255);
            $table->string('status', 50)->default('active');
            $table->integer('priority')->default(0);
            $table->string('external_reference', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('stale_after_minutes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'priority']);
        });

        Schema::create('inventory_source_store_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->unique(['inventory_source_id', 'store_id']);
        });

        Schema::create('inventory_source_market_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            $table->unique(['inventory_source_id', 'market_id']);
        });

        Schema::create('inventory_source_channel_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->unique(['inventory_source_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_source_channel_assignments');
        Schema::dropIfExists('inventory_source_market_assignments');
        Schema::dropIfExists('inventory_source_store_assignments');
        Schema::dropIfExists('inventory_sources');
        Schema::dropIfExists('units_of_measure');
    }
};
