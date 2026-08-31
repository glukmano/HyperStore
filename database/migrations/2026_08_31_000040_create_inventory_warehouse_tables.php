<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->string('type', 50)->default('owned'); // owned, vendor, supplier, 3pl, virtual, dropship
            $table->string('status', 50)->default('active'); // active, inactive
            $table->string('country_code', 2);
            $table->string('state_code', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 50)->nullable();
            $table->string('address_line_1', 255)->nullable();
            $table->string('address_line_2', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 50)->default('UTC');
            $table->integer('priority')->default(0);
            $table->boolean('is_default')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
