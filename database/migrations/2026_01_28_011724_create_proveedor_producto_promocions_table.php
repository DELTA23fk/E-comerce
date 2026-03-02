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
        Schema::create('proveedor_producto_promociones', function (Blueprint $table) {
            $table->id();
            // ----------------------------------------------------------------
            // Descuento
            // ----------------------------------------------------------------
            $table->decimal('total_descuento', 8, 2)->nullable()->unsigned();
            $table->string('moneda_descuento', 10)->nullable();
            $table->string('tipo_descuento')->nullable();             // "Special Bid", "Federal", "Promo Discount", etc.

            // ----------------------------------------------------------------
            // Precio en moneda original del proveedor (USD o MXN)
            // ----------------------------------------------------------------
            $table->string('moneda_precio_original', 10)->nullable(); // 'USD' o 'MXN'
            $table->decimal('precio_con_descuento', 15, 4)->nullable()->unsigned();

            // Equivalente MXN — solo se llena si moneda_precio_original = 'USD'
            $table->decimal('precio_con_descuento_mxn', 15, 4)->nullable()->unsigned();
            $table->decimal('tipo_cambio_usado', 10, 4)->nullable()->unsigned(); // TC al momento de guardar

            // ----------------------------------------------------------------
            // Identificación de la promoción
            // ----------------------------------------------------------------
            $table->string('clave_promocion')->nullable();
            $table->text('descripcion_promocion')->nullable();

            // ----------------------------------------------------------------
            // Vigencia — separada porque puede ser fecha o texto libre
            // Ej fecha: "2024-09-28"
            // Ej texto: "por cantidad", "hasta agotar stock", "vigente"
            // ----------------------------------------------------------------
            $table->date('fecha_inicio')->nullable();                 // N1: specialPricingEffectiveDate
            $table->date('expiracion_fecha')->nullable();             // cuando viene como fecha parseable
            $table->string('expiracion_texto')->nullable();           // cuando no es fecha válida

            // ----------------------------------------------------------------
            // Cantidades
            // ----------------------------------------------------------------
            $table->integer('cantidad_minima')->nullable()->unsigned();          // N1: specialPricingMinQuantity
            $table->integer('disponible_en_promocion')->nullable()->unsigned();  // CVA + N1

            // ----------------------------------------------------------------
            // Precio de referencia para el frontend
            // Siempre en MXN
            // ----------------------------------------------------------------
            $table->decimal('precio_regular', 15, 4)->nullable()->unsigned();

            // ----------------------------------------------------------------
            // Estado
            // ----------------------------------------------------------------
            $table->boolean('es_oferta')->default(false);

            // ----------------------------------------------------------------
            // Relación
            // ----------------------------------------------------------------
            $table->foreignId('proveedor_producto_id')
                ->constrained('proveedor_productos')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->softDeletes();
            $table->timestamps();

            // ----------------------------------------------------------------
            // Índices
            // ----------------------------------------------------------------

            // Unique solo cuando existe clave_promocion (MySQL permite múltiples NULLs en unique)
            $table->unique(
                ['proveedor_producto_id', 'clave_promocion'],
                'unique_promo_por_proveedor_producto_clave'
            );

            // Para consultas frecuentes: "dame las promos activas de este producto"
            $table->index(['proveedor_producto_id', 'es_oferta'], 'idx_promo_activa');

            // Para limpiar promos expiradas por fecha
            $table->index('expiracion_fecha', 'idx_promo_expiracion_fecha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proveedor_producto_promociones');
    }
};
