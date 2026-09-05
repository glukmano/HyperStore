<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-20: `price_books.customer_group_id` (and `Modules\Promotions\DTOs\
 * PromotionContext::$customerGroupId`) have existed since Phase-04 as a bare,
 * unconstrained integer column — no `customer_groups` table has ever backed
 * them. Phase-20 B2B wholesale/negotiated pricing is the first real consumer
 * of this concept, so this migration completes the pre-existing partial
 * wiring rather than inventing a new one: it creates the actual table and
 * retroactively adds the FK constraint `price_books.customer_group_id`
 * always should have had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('code', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE price_books ADD CONSTRAINT fk_price_books_customer_group FOREIGN KEY (customer_group_id) REFERENCES customer_groups(id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE price_books DROP CONSTRAINT IF EXISTS fk_price_books_customer_group');
        }

        Schema::dropIfExists('customer_groups');
    }
};
