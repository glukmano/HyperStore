<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_source_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('inventory_source_id')->constrained('inventory_sources')->cascadeOnDelete();
            $table->string('fulfillment_mode', 50)->default('own_stock'); // own_stock, vendor_stock, dropship, 3pl, digital
            $table->integer('priority')->default(0);
            $table->string('status', 30)->default('active');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'inventory_source_id'], 'fulfillment_src_cfg_unique');
        });

        Schema::create('fulfillment_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('strategy_type', 50)->default('priority_minimize_split');
            $table->jsonb('configuration')->nullable();
            $table->boolean('is_default')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'is_default'], 'fulfillment_strategies_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_strategies');
        Schema::dropIfExists('fulfillment_source_configurations');
    }
};
