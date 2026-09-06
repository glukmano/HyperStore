<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-21 Digital Delivery: DigitalAsset (private-disk-only files),
 * CustomerEntitlement (one shared model — Owner Delta D.36 — for both
 * Digital and Subscription access), DigitalAccessLog (audit trail), and
 * LicenseKeyPool with a DB-enforced one-key-per-OrderItem guarantee
 * (Owner Delta §14).
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('digital_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('disk', 64)->default('digital-assets');
            $table->string('path', 500);
            $table->unsignedInteger('version')->default(1);
            $table->string('checksum', 128)->nullable();
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('download_expiry_days')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'product_id']);
        });

        // Owner Delta D.36: one shared, explicitly-modeled entitlement table
        // for both Digital downloads and Subscription access — distinct from
        // the immutable OrderItem commercial record. Unique per
        // (source_type, source_uuid) so a duplicate grant attempt for the
        // same OrderItem/Subscription is always idempotent.
        Schema::create('customer_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->string('entitlement_type', 32);
            $table->string('source_type', 64);
            $table->string('source_uuid', 64);
            $table->timestampTz('granted_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'source_type', 'source_uuid'], 'uq_customer_entitlements_source');
            $table->index(['customer_profile_id', 'entitlement_type']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE customer_entitlements ADD CONSTRAINT chk_customer_entitlements_type CHECK (entitlement_type IN ('digital_download', 'subscription_access'))");
        }

        Schema::create('digital_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_entitlement_id')->constrained('customer_entitlements')->cascadeOnDelete();
            $table->string('ip_hash', 64);
            $table->text('user_agent')->nullable();
            $table->timestampTz('accessed_at');

            $table->index(['customer_entitlement_id', 'accessed_at']);
        });

        // Owner Delta §14: allocation, not physical Inventory decrement —
        // a key is an individually distinct secret. The plaintext code is
        // NEVER stored (only an application-level encrypted value); a
        // partial unique index on assigned_order_item_id is the DB-level
        // backstop guaranteeing one OrderItem receives at most one key,
        // never merely an app-level check.
        Schema::create('license_key_pools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->text('code_encrypted');
            $table->string('status', 20)->default('available');
            $table->unsignedBigInteger('assigned_order_item_id')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index('assigned_order_item_id');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE license_key_pools ADD CONSTRAINT chk_license_key_pools_status CHECK (status IN ('available', 'reserved', 'assigned', 'revoked'))");
            DB::statement('CREATE UNIQUE INDEX uq_license_key_pools_one_order_item ON license_key_pools (assigned_order_item_id) WHERE assigned_order_item_id IS NOT NULL');
        }

        // Order-time snapshot: the download/expiry policy in effect AT THE
        // TIME of purchase, so a later DigitalAsset policy edit never alters
        // a historical Order's entitlement terms.
        Schema::table('order_items', function (Blueprint $table): void {
            $table->json('entitlement_terms_snapshot')->nullable()->after('customization_metadata_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('entitlement_terms_snapshot');
        });
        Schema::dropIfExists('license_key_pools');
        Schema::dropIfExists('digital_access_logs');
        Schema::dropIfExists('customer_entitlements');
        Schema::dropIfExists('digital_assets');
    }
};
