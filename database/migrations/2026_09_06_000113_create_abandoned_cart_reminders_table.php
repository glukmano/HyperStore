<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-19 Owner Delta correction §16: race-safe reminder dispatch tracking —
 * the unique constraint is the actual duplicate-send guard, not merely an
 * audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abandoned_cart_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->unsignedTinyInteger('reminder_sequence');
            $table->timestampTz('sent_at')->useCurrent();
            $table->timestamps();

            $table->unique(['tenant_id', 'cart_id', 'reminder_sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abandoned_cart_reminders');
    }
};
