<?php

namespace App\Services\Orders;

use App\Data\Payment\WebhookResultData;
use App\Enum\Order\OrderStatusEnum;
use App\Enum\Order\PaymentStatusEnum;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentWebhookException;
use App\Factories\PaymentGatewayFactory;
use App\Models\Pedido;
use App\Models\TransaccionPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookProcessorService
{
    public function __construct(
        private readonly PaymentGatewayFactory       $paymentGatewayFactory,
        private readonly SuborderRegistrationService $suborderRegistration,
    ) {}

    public function procesar(string $gateway, array $payload, array $headers): bool
    {
        $gatewayService = $this->paymentGatewayFactory->crear($gateway);

        // El gateway ya llama PaymentClient::get() internamente — confiar en ese status
        $resultado = $gatewayService->procesarWebhook($payload, $headers);

        if ($resultado->tipoEvento === 'other') {
            return false;
        }

        // Idempotencia: si ya existe transacción aprobada, ignorar
        $yaExiste = TransaccionPago::where('gateway_payment_id', $resultado->gatewayPaymentId)
            ->where('status', PaymentStatusEnum::APPROVED->value)
            ->exists();

        if ($yaExiste) {
            Log::info('[WebhookProcessor] Webhook ignorado — ya procesado', [
                'gateway'            => $gateway,
                'gateway_payment_id' => $resultado->gatewayPaymentId,
            ]);
            return false;
        }

        // ✅ FIX Bug 1: quitar el bloque de doble verificación completo.
        // El gateway ya consultó la API. Si el status sigue pending aquí,
        // guardamos la transacción como pending y dejamos que el próximo
        // webhook de MP (con approved) complete el flujo.

        // ✅ FIX Bug 3 (NPE): localizar ANTES de operar
        $pedido = $this->localizarPedido($gateway, $resultado);

        if (!$pedido) {
            Log::warning('[WebhookProcessor] Pedido no encontrado en webhook', [
                'gateway'          => $gateway,
                'gateway_order_id' => $resultado->gatewayOrderId,
                'payment_id'       => $resultado->gatewayPaymentId,
                'external_ref'     => $resultado->metadata['external_reference'] ?? null,
            ]);
            return false;
        }

        Log::info('[WebhookProcessor] Procesando webhook', [
            'pedido_id'   => $pedido->id,
            'gateway'     => $gateway,
            'tipo_evento' => $resultado->tipoEvento,
            'status'      => $resultado->status,
            'monto'       => $resultado->monto,
        ]);

        return DB::transaction(function () use ($pedido, $resultado, $gateway) {
            if ($resultado->esPago()) {
                return $this->procesarEventoPago($pedido, $resultado, $gateway);
            }

            if ($resultado->esReembolso()) {
                return $this->procesarEventoReembolso($pedido, $resultado, $gateway);
            }

            return false;
        });
    }

    // =========================================================================
    // PRIVADOS
    // =========================================================================

    private function procesarEventoPago(
    Pedido            $pedido,
    WebhookResultData $resultado,
    string            $gateway,
    ): bool {
        // ✅ SIEMPRE guardar la transacción — pending o approved
        // updateOrCreate permite que el webhook approved sobreescriba el pending
        TransaccionPago::updateOrCreate(
            [
                'pedido_id'        => $pedido->id,
                'gateway'          => $gateway,
                'tipo'             => 'pago',
                'gateway_order_id' => $resultado->gatewayOrderId,
            ],
            [
                'gateway_payment_id' => $resultado->gatewayPaymentId,
                'status'             => $resultado->status,
                'monto'              => $resultado->monto,
                'moneda'             => $resultado->moneda,
                'metadata'           => $resultado->metadata,
                'response_raw'       => $resultado->rawPayload,
            ],
        );

        // ✅ SIEMPRE sincronizar el pedido con el status real
        $this->sincronizarEstadoPedido($pedido, $resultado);

        // Solo disparar Fase 2 si está aprobado
        if ($resultado->aprobado()) {
            Log::info('[WebhookProcessor] Pago aprobado — iniciando subórdenes', [
                'pedido_id'  => $pedido->id,
                'payment_id' => $resultado->gatewayPaymentId,
            ]);
            return $this->suborderRegistration->procesar($pedido->fresh());
        }

        Log::info('[WebhookProcessor] Transacción guardada con status no aprobado — esperando webhook final', [
            'pedido_id' => $pedido->id,
            'status'    => $resultado->status,
        ]);

        return false;
    }

    private function procesarEventoReembolso(
        Pedido            $pedido,
        WebhookResultData $resultado,
        string            $gateway,
    ): bool {
        $esTotal  = abs($resultado->monto - (float) $pedido->precio_total) < 0.01;
        $tipo     = $esTotal ? 'reembolso' : 'reembolso_parcial';

        // PASO 1: Registrar TransaccionPago de reembolso
        TransaccionPago::create([
            'pedido_id'          => $pedido->id,
            'tipo'               => $tipo,
            'gateway'            => $gateway,
            'gateway_order_id'   => $resultado->gatewayOrderId,
            'gateway_payment_id' => $resultado->gatewayPaymentId,
            'status'             => $resultado->status,
            'monto'              => $resultado->monto,
            'moneda'             => $resultado->moneda,
            'metadata'           => $resultado->metadata,
            'response_raw'       => $resultado->rawPayload,
            'motivo'             => 'Reembolso procesado por gateway',
        ]);

        // PASO 2: Sincronizar Pedido
        $pedido->increment('monto_reembolsado', $resultado->monto);
        $pedido->update([
            'payment_status' => $esTotal
                ? PaymentStatusEnum::REFUNDED->value
                : 'partial_refunded',
            'estatus' => OrderStatusEnum::CANCELADO->value,
        ]);

        Log::info('[WebhookProcessor] Reembolso registrado', [
            'pedido_id' => $pedido->id,
            'tipo'      => $tipo,
            'monto'     => $resultado->monto,
        ]);

        return true;
    }

    /**
     * ✅ Sincroniza payment_status y estatus del pedido según el resultado del pago.
     * Método centralizado para no duplicar lógica.
     */
    private function sincronizarEstadoPedido(Pedido $pedido, WebhookResultData $resultado): void
    {
        [$paymentStatus, $orderStatus] = $this->resolverEstados($resultado->status);

        $datos = [
            'payment_status' => $paymentStatus,
            'estatus'        => $orderStatus,
        ];

        // Solo actualizar campos de pago si viene aprobado
        if ($resultado->aprobado()) {
            $datos['gateway_payment_id'] = $resultado->gatewayPaymentId;
            $datos['monto_pagado']       = $resultado->monto;
            $datos['moneda_cobro']       = $resultado->moneda;
            $datos['fecha_pago']         = now();
        }

        $pedido->update($datos);

        Log::info('[WebhookProcessor] Pedido sincronizado', [
            'pedido_id'      => $pedido->id,
            'payment_status' => $paymentStatus,
            'estatus'        => $orderStatus,
        ]);
    }

    /**
     * ✅ Mapeo centralizado: status de pago → (payment_status, order_status)
     */
    private function resolverEstados(string $paymentStatus): array
    {
        return match ($paymentStatus) {
            PaymentStatusEnum::APPROVED->value => [
                PaymentStatusEnum::APPROVED->value,
                OrderStatusEnum::PENDIENTE->value,
            ],
            // ✅ FIX: in_process es diferente a pending — MP aún está procesando
            PaymentStatusEnum::IN_PROCESS->value,
            PaymentStatusEnum::PENDING->value => [
                PaymentStatusEnum::PENDING->value,
                OrderStatusEnum::PENDIENTE_PAGO->value,
            ],
            PaymentStatusEnum::REJECTED->value => [
                PaymentStatusEnum::REJECTED->value,
                OrderStatusEnum::FALLIDO->value,
            ],
            PaymentStatusEnum::CANCELLED->value => [
                PaymentStatusEnum::CANCELLED->value,
                OrderStatusEnum::CANCELADO->value,
            ],
            PaymentStatusEnum::REFUNDED->value,
            'charged_back' => [
                PaymentStatusEnum::REFUNDED->value,
                OrderStatusEnum::CANCELADO->value,
            ],
            default => [
                $paymentStatus,
                OrderStatusEnum::PENDIENTE_PAGO->value,
            ],
        };
    }

    private function localizarPedido(string $gateway, WebhookResultData $resultado): ?Pedido
    {
        // Estrategia 1: por gateway_order_id (preference_id de MP)
        if ($resultado->gatewayOrderId) {
            $pedido = Pedido::where('gateway_order_id', $resultado->gatewayOrderId)
                ->where('payment_gateway', $gateway)
                ->first();

            if ($pedido) return $pedido;
        }

        // Estrategia 2: por external_reference (folio del pedido)
        $externalRef = $resultado->metadata['external_reference'] ?? null;

        if ($externalRef) {
            $pedido = Pedido::where('folio', $externalRef)->first();
            if ($pedido) return $pedido;
        }

        return null;
    }
}