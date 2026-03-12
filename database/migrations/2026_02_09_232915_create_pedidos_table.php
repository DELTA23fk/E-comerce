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
            $table->string('observaciones')->nullable();
            $table->decimal('precio_total',10,2)->unsigned();
            $table->decimal('precio_total_productos',10,2)->unsigned();
            $table->decimal('precio_total_envio',10,2)->unsigned();
            $table->string('moneda_cobro', 3)->default('MXN');
            // ── Estado del pedido (separado del estado del pago) ──────────────
            // pending_payment → processing → partial → completed | failed | cancelled
            $table->string('estatus')->default('pendiente_pago');

            $table->foreignId('cliente_id')->constrained('clientes')->onDelete('restrict')->onUpdate('cascade');

            //pagos
            // ── Pasarela de pago ──────────────────────────────────────────────
            // mercadopago | paypal | manual | null (aún no se elige)
            $table->string('payment_gateway', 20)->nullable();

            // ID de la preferencia/orden ANTES de que el usuario pague:
            //   MercadoPago → preference_id
            //   PayPal      → order_id
            $table->string('gateway_order_id')->nullable();

            // ID de la transacción CONFIRMADA después del pago:
            //   MercadoPago → payment_id
            //   PayPal      → capture_id
            $table->string('gateway_payment_id')->nullable();

            // Estado normalizado de pago:
            // pending | approved | rejected | refunded | partial_refunded | in_mediation | charged_back
            $table->string('payment_status', 30)->default('pending');

            $table->decimal('monto_pagado',       10, 2)->default(0);
            $table->decimal('monto_reembolsado',  10, 2)->default(0);
            $table->timestamp('fecha_pago')->nullable();

            // ── Control de errores ────────────────────────────────────────────
            $table->json('errores_detallados')->nullable();
            $table->boolean('requiere_atencion_manual')->default(false);

            
            $table->timestamps();
            $table->softDeletes();

           $table->index('folio');
            $table->index('estatus');
            $table->index('payment_status');
            $table->index('payment_gateway');
            $table->index('gateway_order_id');
            $table->index('gateway_payment_id');
            $table->index(['cliente_id', 'estatus']);
            $table->index(['cliente_id', 'payment_status']);
            $table->index('fecha_pedido');
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
