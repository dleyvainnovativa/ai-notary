<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_create_postal_codes_table.php
    public function up(): void
    {
        Schema::create('postal_codes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_postal', 5)->index();   // "01000"
            $table->string('colonia_code', 10)->nullable(); // c_Colonia "0001"
            $table->string('colonia');                       // "San Ángel"
            $table->timestamps();

            $table->index(['codigo_postal', 'colonia']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::drop('postal_codes');
    }
};
