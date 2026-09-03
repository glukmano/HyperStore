<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->uuid('vendor_uuid_snapshot')->nullable()->after('customization_metadata_snapshot');
            $table->string('vendor_name_snapshot', 255)->nullable()->after('vendor_uuid_snapshot');
            $table->uuid('vendor_listing_uuid_snapshot')->nullable()->after('vendor_name_snapshot');
            $table->bigInteger('commission_basis_minor')->nullable()->after('vendor_listing_uuid_snapshot');
            $table->integer('commission_rate_bps')->nullable()->after('commission_basis_minor');
            $table->bigInteger('commission_fixed_fee_minor')->nullable()->after('commission_rate_bps');
            $table->bigInteger('commission_amount_minor')->nullable()->after('commission_fixed_fee_minor');
            $table->string('commission_currency', 3)->nullable()->after('commission_amount_minor');
            $table->string('commission_rule_ref', 128)->nullable()->after('commission_currency');

            // Non-authoritative navigation aids
            $table->unsignedBigInteger('vendor_id')->nullable()->after('commission_rule_ref');
            $table->unsignedBigInteger('vendor_listing_id')->nullable()->after('vendor_id');

            $table->foreign('vendor_id', 'fk_order_items_vendor')
                ->references('id')->on('vendors')->onDelete('restrict');
            $table->foreign('vendor_listing_id', 'fk_order_items_vendor_listing')
                ->references('id')->on('vendor_listings')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign('fk_order_items_vendor_listing');
            $table->dropForeign('fk_order_items_vendor');
            $table->dropColumn([
                'vendor_uuid_snapshot',
                'vendor_name_snapshot',
                'vendor_listing_uuid_snapshot',
                'commission_basis_minor',
                'commission_rate_bps',
                'commission_fixed_fee_minor',
                'commission_amount_minor',
                'commission_currency',
                'commission_rule_ref',
                'vendor_id',
                'vendor_listing_id',
            ]);
        });
    }
};
