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
        Schema::create('tipo_cambio_monedas', function (Blueprint $table) {
            $table->id();
            $table->string('moneda_origen', 3)->default('USD');
            $table->string('moneda_destino', 3)->default('MXN');
            
            // Valor tal cual viene de la API (ej: 18.5024)
            $table->decimal('valor_api', 15, 4)->unsigned(); 
            
            // Porcentaje de margen (ej: 2.50 para 2.5%)
            $table->decimal('porcentaje_margen', 5, 2)->default(2.00);
            
            // Valor calculado con el que opera la tienda
            $table->decimal('valor_final', 15, 4)->unsigned();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tipo_cambio_monedas');
    }
};
