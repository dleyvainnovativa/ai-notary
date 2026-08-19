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
        Schema::create('notario_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Configuracion line pieces: 2026|001|035|28  → año is dynamic; these are the fixed 3:
            $table->string('clave')->default('001');           // 2nd segment
            $table->string('entidad')->default('035');         // 3rd segment
            $table->string('num_notaria')->default('28');      // 4th segment
            $table->string('nombre_notario')->nullable();
            $table->string('entidad_federativa')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::drop('notario_profiles');
    }
};
