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
            $table->foreignId('proveedor_producto_id')->unique()->constrained()->onDelete('restrict');
            
            // Lo que el cliente ve
            $table->string('moneda_venta', 3)->default('MXN');
            $table->decimal('precio_venta', 15, 2)->unsigned();
            $table->decimal('precio_anterior', 15, 2)->nullable()->unsigned();
            
            // Lo que te costó (Ingram u otro que de un precio base)
            $table->decimal('precio_base_producto', 15, 2)->unsigned();
            $table->string('moneda_base_producto', 3)->default('USD');
            $table->decimal('precio_recomendado_proveedor', 15, 2)->nullable();
            
            // Datos del cálculo
            $table->decimal('tipo_cambio_usado_mxn', 15, 4)->nullable(); // El valor de tu tabla tipo_cambios
            $table->decimal('porcentaje_utilidad', 5, 2)->default(0); 
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
