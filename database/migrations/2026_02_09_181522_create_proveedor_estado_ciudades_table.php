<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('proveedor_estado_ciudades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_estado_id')->constrained('proveedor_estados')->onDelete('cascade')->onUpdate('cascade');
            $table->string('clave')->unique(); // ID de CVA (ej: "5782")
            $table->string('descripcion');     // SAN QUINTIN
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proveedor_estado_ciudades');
    }
};
