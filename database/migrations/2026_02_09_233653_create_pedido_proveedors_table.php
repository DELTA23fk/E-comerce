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
        Schema::create('pedido_proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->onDelete('restrict')->onUpdate('cascade');
            $table->foreignId('proveedor_id')->constrained('proveedores')->onDelete('restrict')->onUpdate('cascade');
            $table->string('folio_pedido')->nullable()->unique();

            $table->string('moneda_cobro_productos', 3);
            $table->decimal('precio_total_productos',15,2)->unsigned();

            $table->string('moneda_cobro_envio', 3)->nullable();
            $table->decimal('precio_total_envio',15,2)->nullable()->unsigned();

            $table->boolean('iva_incluido')->default(false);
            $table->boolean('envio_gratis')->default(false);
            $table->date('fecha_entrega_estimada')->nullable();
            // ── Estado del subpedido ──────────────────────────────────────────
            // creado | en_proceso | enviado | entregado | cancelado | fallido
            $table->string('status');
            // Tipo de cambio efectivamente usado para la conversión de productos.
            // Null si la moneda ya era MXN (sin conversión).
            $table->decimal('tipo_cambio_aplicado', 10, 4)->nullable();
            $table->decimal('precio_total_productos_mxn', 15, 2)->nullable()->unsigned();
            $table->decimal('precio_total_envio_mxn', 15, 2)->nullable()->unsigned();
            $table->decimal('precio_total_mxn', 15, 2)->nullable()->unsigned();

            $table->string('email_agente')->nullable();
            $table->string('email_almacen')->nullable();
            $table->string('origen_envio', 50)->nullable();

            //pagos
            $table->decimal('monto_reembolsado_mxn', 15, 2)->default(0);
            $table->string('motivo_reembolso')->nullable();
            $table->timestamp('fecha_reembolso')->nullable();
            $table->text('error_mensaje')->nullable();

            //error
            $table->json('error_detalle')->nullable();
            $table->boolean('requiere_atencion_manual')->default(false);
            
            $table->timestamps();
            $table->softDeletes();
            $table->index(['pedido_id', 'status']);
            $table->index('folio_pedido');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedido_proveedores');
    }
};
