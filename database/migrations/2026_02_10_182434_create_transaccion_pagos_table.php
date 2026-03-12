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
            $table->foreignId('pedido_id')
                  ->constrained('pedidos')
                  ->onDelete('restrict');

            // Null = transacción aplica al pedido completo.
            // Filled = reembolso parcial de un subpedido específico.
            $table->foreignId('pedido_proveedor_id')
                  ->nullable()
                  ->constrained('pedido_proveedores')
                  ->onDelete('restrict');

            // ── Clasificación ─────────────────────────────────────────────────
            // pago | reembolso | reembolso_parcial | contracargo | ajuste
            $table->string('tipo', 30);

            // mercadopago | paypal
            $table->string('gateway', 20);

            // ── IDs del gateway ───────────────────────────────────────────────
            // ID de la intención/orden (creado antes del pago):
            //   MercadoPago → preference_id
            //   PayPal      → order_id
            $table->string('gateway_order_id')->nullable();

            // ID del evento confirmado (cobro, captura o reembolso):
            //   MercadoPago cobro     → payment_id
            //   MercadoPago reembolso → refund_id
            //   PayPal cobro          → capture_id
            //   PayPal reembolso      → refund_id
            $table->string('gateway_payment_id');

            // ── Estado normalizado entre gateways ─────────────────────────────
            // pending | approved | rejected | cancelled | refunded | in_mediation | charged_back
            $table->string('status', 30);

            // ── Montos ────────────────────────────────────────────────────────
            $table->decimal('monto',   10, 2);
            $table->string('moneda', 3)->default('MXN');

            // ── Metadatos específicos por gateway ─────────────────────────────
            // MercadoPago:
            //   payment_method_id, payment_type_id, installments,
            //   issuer_id, card.last_four_digits, payer.email,
            //   fee_details[].amount, merchant_order_id
            //
            // PayPal:
            //   payer.email_address, payer.payer_id,
            //   payment_source.card.last_digits, seller_receivable_breakdown,
            //   processor_response.avs_code
            $table->json('metadata')->nullable();

            // Payload crudo completo del webhook/API (para auditoría y debugging).
            $table->json('response_raw')->nullable();

            // ── Contexto del evento ───────────────────────────────────────────
            $table->string('motivo')->nullable();          // para reembolsos
            $table->ipAddress('ip_cliente')->nullable();   // IP del pagador
            $table->string('user_agent')->nullable();      // navegador del pagador

            $table->timestamps();
            $table->softDeletes(); // Sin softDeletes: registro contable, nunca se borra.
            // Sin softDeletes: registro contable, nunca se borra.

            // ── Índices ───────────────────────────────────────────────────────
            $table->index(['pedido_id', 'tipo']);
            $table->index(['pedido_id', 'status']);
            $table->index(['gateway', 'gateway_payment_id']);
            $table->index(['gateway', 'gateway_order_id']);
            $table->index('gateway_order_id');
            $table->index('gateway_payment_id');
            $table->index('status');
            $table->index('created_at');  // útil para reportes contables por fecha

            // Unicidad: no puede haber dos transacciones aprobadas con el mismo payment_id
            // (previene doble procesamiento de webhooks duplicados)
            $table->unique(['gateway_payment_id', 'tipo', 'status'], 'uniq_payment_tipo_status');
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
