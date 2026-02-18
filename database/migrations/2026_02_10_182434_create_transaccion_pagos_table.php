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
        Schema::create('transacciones_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->onDelete('cascade');
            $table->foreignId('pedido_proveedor_id')->nullable()->constrained('pedido_proveedores');
            $table->string('tipo'); // 'pago', 'reembolso'
            $table->string('gateway'); // 'mercadopago', 'stripe'
            $table->string('transaction_id'); // ID externo
            $table->string('status'); // 'pending', 'approved', 'rejected', 'refunded'
            $table->decimal('monto', 10, 2);
            $table->string('moneda', 3)->default('MXN');
            $table->json('metadata')->nullable(); // Datos adicionales del gateway
            $table->text('response_data')->nullable(); // Respuesta completa del API
            $table->string('motivo')->nullable(); // Para reembolsos
            $table->timestamps();
            $table->softDeletes();

            $table->index(['pedido_id', 'tipo']);
            $table->index('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transacciones_pagos');
    }
};
