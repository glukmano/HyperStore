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
            $table->foreignId('clicked_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('locale', 10);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'normalized_query']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'result_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
