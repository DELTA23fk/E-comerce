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
        Schema::create('proveedor_producto_precios', function (Blueprint $table) {
            $table->id();
            $table->decimal('precio_actual',15,2)->unsigned();
            $table->decimal('precio_anterior',15,2)->unsigned();
            $table->dateTime('ultima_actualizacion');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proveedor_producto_precios');
    }
};
