<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // xxxx_create_documents_table.php
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('token_reservations')->nullOnDelete();

            $table->string('module_slug');          // which module will process it
            $table->string('module_version')->nullable();

            $table->string('original_filename');     // for display only
            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('page_count')->nullable();

            // temp file location — nulled out the moment the file is deleted
            $table->string('temp_path')->nullable();

            $table->enum('status', [
                'uploaded',
                'extracting',
                'processing',
                'requires_review',
                'completed',
                'failed',
            ])->default('uploaded');

            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->unsignedInteger('processing_time_ms')->nullable();

            $table->unsignedBigInteger('organization_id')->nullable(); // Phase 9 prep
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('created_at'); // for the cleanup sweep
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
