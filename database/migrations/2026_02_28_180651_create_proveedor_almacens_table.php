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
        Schema::create('proveedor_almacenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')
                ->constrained('proveedores')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->string('almacen_id_externo')->nullable(); // "1", "46", "20"
            $table->string('nombre');                         // nombre canónico
            $table->string('codigo_postal', 10)->nullable();
            $table->boolean('es_principal')->nullable()->default(false);  // sucursal principal
            $table->boolean('es_cd')->default(false);         // centro de distribución

            // Un almacén único por proveedor + nombre canónico
            $table->unique(
                ['proveedor_id', 'nombre'],
                'unique_almacen_proveedor_nombre'
            );

            $table->index('proveedor_id', 'idx_almacen_proveedor');
            $table->timestamps();
            $table->softDeletes();
        });

         // ── Stock por proveedor_producto × almacén ────────────────────────────
        Schema::create('almacen_producto_stock', function (Blueprint $table) {
            $table->id();

            $table->foreignId('proveedor_almacen_id')
                ->constrained('proveedor_almacenes')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->foreignId('proveedor_producto_id')
                ->constrained('proveedor_productos')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            $table->integer('cantidad')->default(0)->unsigned();

            // Solo para proveedores que lo soporten (Ingram, etc.)
            $table->integer('backorder')->nullable()->unsigned();
            $table->date('eta_backorder')->nullable();

            $table->timestamp('ultima_actualizacion')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(
                ['proveedor_almacen_id', 'proveedor_producto_id'],
                'unique_stock_almacen_producto'
            );

            $table->index(
                ['proveedor_producto_id', 'proveedor_almacen_id'],
                'idx_stock_producto_almacen'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proveedor_almacenes');
        Schema::dropIfExists('almacen_producto_stock');
    }
};
