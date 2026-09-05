<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-20 Booking. Owner Delta §4: `booking_services` is the smallest
 * Booking-owned configuration model — Catalog Product stays the sellable
 * identity, duration/timezone/buffer live here, not scattered onto Product.
 * A Service is fulfillable by one or more eligible `booking_resources` via
 * an explicit pivot, so a Customer's slot selection is provably
 * Product -> Service -> eligible Resource -> generated Slot, never an
 * arbitrary Tenant-wide Resource. Owner Delta §5: `bookings` has an
 * UNCONDITIONAL unique(booking_slot_id, checkout_session_uuid) so a retried
 * hold-then-confirm sequence never creates a second row regardless of
 * status. Owner Delta §6/§8: no cached confirmed_count/held_count anywhere —
 * used capacity is always a live COUNT(*) over locked `bookings` rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('booking_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('duration_minutes');
            $table->string('timezone', 64);
            $table->unsignedInteger('buffer_minutes')->default(0);
            $table->timestamps();
        });

        Schema::create('booking_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE booking_resources ADD CONSTRAINT chk_booking_resources_capacity CHECK (capacity >= 0)');
        }

        Schema::create('booking_service_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_service_id')->constrained('booking_services')->cascadeOnDelete();
            $table->foreignId('booking_resource_id')->constrained('booking_resources')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['booking_service_id', 'booking_resource_id']);
        });

        Schema::create('availability_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('booking_resource_id')->constrained('booking_resources')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('timezone', 64);
            $table->timestamps();

            $table->index(['booking_resource_id', 'weekday']);
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE availability_rules ADD CONSTRAINT chk_availability_rules_weekday CHECK (weekday BETWEEN 0 AND 6)');
        }

        Schema::create('availability_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('booking_resource_id')->constrained('booking_resources')->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 20);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamps();

            $table->index(['booking_resource_id', 'date']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE availability_exceptions ADD CONSTRAINT chk_availability_exceptions_type CHECK (type IN ('closure', 'extra'))");
        }

        Schema::create('booking_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('booking_resource_id')->constrained('booking_resources')->cascadeOnDelete();
            // Owner Delta §4: a slot's duration is the Service's own
            // duration_minutes — a Resource eligible for multiple Services
            // of different durations generates one distinct slot series per
            // (service, resource) pair, never an ambiguous shared duration.
            $table->foreignId('booking_service_id')->constrained('booking_services')->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('capacity');
            $table->timestamps();

            // Owner Delta §8: repeated scheduler runs are idempotent.
            $table->unique(['booking_service_id', 'booking_resource_id', 'starts_at']);
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE booking_slots ADD CONSTRAINT chk_booking_slots_capacity CHECK (capacity >= 0)');
        }

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->foreignId('booking_service_id')->constrained('booking_services')->cascadeOnDelete();
            $table->foreignId('booking_slot_id')->constrained('booking_slots')->cascadeOnDelete();
            $table->string('status', 20)->default('held');
            $table->timestampTz('hold_expires_at')->nullable();
            $table->uuid('checkout_session_uuid');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamps();

            // Owner Delta §5: unconditional — survives held -> confirmed.
            $table->unique(['booking_slot_id', 'checkout_session_uuid']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE bookings ADD CONSTRAINT chk_bookings_status CHECK (status IN ('held', 'confirmed', 'cancelled', 'completed'))");
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('booking_id')->nullable()->after('reserve_met');
            $table->timestampTz('booking_slot_starts_at_snapshot')->nullable()->after('booking_id');
            $table->string('booking_timezone_snapshot', 64)->nullable()->after('booking_slot_starts_at_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['booking_id', 'booking_slot_starts_at_snapshot', 'booking_timezone_snapshot']);
        });
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_slots');
        Schema::dropIfExists('availability_exceptions');
        Schema::dropIfExists('availability_rules');
        Schema::dropIfExists('booking_service_resources');
        Schema::dropIfExists('booking_resources');
        Schema::dropIfExists('booking_services');
    }
};
