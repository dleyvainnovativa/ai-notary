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
    // xxxx_create_token_reservations_table.php  — tracks live holds + TTL
    public function up(): void
    {
        Schema::create('token_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('token_transactions')->cascadeOnDelete();
            $table->enum('status', ['active', 'consumed', 'released'])->default('active');
            $table->timestamp('expires_at');      // TTL — reclaimed if still active past this
            $table->json('context_json')->nullable(); // e.g. {document_id, module}
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_reservations');
    }
};
