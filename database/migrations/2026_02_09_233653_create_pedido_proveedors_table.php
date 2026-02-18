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
            $table->decimal('precio_total_productos',15,2)->unsigned();
            $table->decimal('precio_total_envio',15,2)->nullable()->unsigned();
            $table->decimal('precio_total',15,2)->nullable()->unsigned();
            $table->boolean('envio_gratis')->default(false);
            $table->string('status');
            $table->string('moneda');
            $table->string('email_agente')->nullable();
            $table->string('email_almacen')->nullable();
            $table->string('origen_envio', 50)->nullable();
            //pagos
            $table->decimal('monto_reembolsado', 15, 2)->default(0);
            $table->string('motivo_reembolso')->nullable();
            $table->timestamp('fecha_reembolso')->nullable();
            $table->text('error_mensaje')->nullable();
            //error
            $table->json('error_detalle')->nullable();
            $table->boolean('requiere_atencion_manual')->default(false);
            
            $table->timestamps();
            $table->softDeletes();
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
