<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->string('provider_code', 255)->default('manual');
            $table->string('status', 30)->default('active');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'carriers_tenant_code_unique');
        });

        Schema::create('carrier_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->integer('transit_days_min')->default(1);
            $table->integer('transit_days_max')->default(3);
            $table->bigInteger('markup_amount')->default(0); // minor units
            $table->decimal('markup_percentage', 5, 2)->default('0.00');
            $table->string('status', 30)->default('active');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['carrier_id', 'code'], 'carrier_services_unique');
        });

        Schema::create('carrier_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('carriers')->cascadeOnDelete();
            $table->string('environment', 30)->default('production'); // sandbox, production
            $table->text('encrypted_credentials'); // encrypted JSON payload
            $table->timestamps();

            $table->unique(['carrier_id', 'environment'], 'carrier_credentials_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_credentials');
        Schema::dropIfExists('carrier_services');
        Schema::dropIfExists('carriers');
    }
};
