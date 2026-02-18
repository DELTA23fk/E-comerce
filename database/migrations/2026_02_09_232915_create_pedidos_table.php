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
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->unique();
            $table->dateTime('fecha_pedido');
            $table->decimal('precio_total',10,2)->unsigned();
            $table->decimal('precio_total_productos',10,2)->unsigned();
            $table->decimal('precio_total_envio',10,2)->unsigned();
            $table->string('estatus')->default('pendiente_pago');
            $table->foreignId('cliente_id')->constrained('clientes')->onDelete('restrict')->onUpdate('cascade');

            //pagos
            $table->string('payment_gateway')->nullable(); // 'mercadopago', 'stripe', etc
            $table->string('payment_id')->nullable(); // ID de la transacción en MP
            $table->string('payment_status')->default('pending'); // pending, approved, rejected, refunded, partial_refunded
            $table->decimal('monto_pagado', 10, 2)->default(0);
            $table->decimal('monto_reembolsado', 10, 2)->default(0);
            $table->timestamp('fecha_pago')->nullable();
            //error
            $table->json('errores_detallados')->nullable();
            $table->boolean('requiere_atencion_manual')->default(false);
            
            $table->timestamps();
            $table->softDeletes();
            $table->index('folio');
            $table->index('payment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
