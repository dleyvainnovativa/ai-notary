<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // xxxx_create_stripe_events_table.php  — the idempotency guard
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();      // Stripe's event id (evt_...)
            $table->string('type');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
