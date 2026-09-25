<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Review drafts: the user's corrected form data, saved as they work, so a
     * reload / closed tab / second deed upload never loses their edits.
     * Encrypted like ai_output_encrypted; longText because appended operations
     * (operaciones acumuladas) can make it grow well past TEXT's 64 KB.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->longText('review_data_encrypted')->nullable()->after('ai_output_encrypted');
            $table->timestamp('review_saved_at')->nullable()->after('review_data_encrypted');
            $table->unsignedInteger('review_version')->default(0)->after('review_saved_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['review_data_encrypted', 'review_saved_at', 'review_version']);
        });
    }
};
