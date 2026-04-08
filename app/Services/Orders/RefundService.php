<?php

namespace App\Services\Orders;

use App\Exceptions\InvalidPaymentStateException;
use App\Exceptions\PaymentGatewayException;
use App\Factories\PaymentGatewayFactory;
use App\Models\Pedido;
use App\Models\TransaccionPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Responsabilidad única: emitir reembolsos contra el gateway de pago.
 *
 * - Valida estado del pedido y saldo reembolsable.
 * - Llama al gateway para procesar el reembolso (parcial o total).
 * - Persiste la transacción y actualiza el pedido.
 * - Expone también un método interno para reembolsos automáticos
 *   (sin lanzar excepción) usado por SuborderRegistrationService.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentGatewayFactory $paymentGatewayFactory,
    ) {}

    // =========================================================================
    // REEMBOLSO MANUAL (público)
    // =========================================================================

    /**
     * Emite un reembolso manual parcial o total contra el gateway de pago original.
     *
     * @param  Pedido       $pedido
     * @param  float|null   $monto   null = reembolso total del saldo pendiente
     * @param  string       $motivo
     * @param  string|null  $admin   Identificador de quien autoriza (auditoría)
     *
     * @return array {
     *   reembolso_id, monto, tipo, gateway,
     *   pedido_id, folio, monto_reembolsado_total, saldo_pendiente
     * }
     *
     * @throws InvalidPaymentStateException
     * @throws \InvalidArgumentException
     * @throws PaymentGatewayException
     */
    public function emitir(
        Pedido  $pedido,
        ?float  $monto  = null,
        string  $motivo = 'Reembolso solicitado por administrador',
        ?string $admin  = null,
    ): array {
        // ── Validaciones ─────────────────────────────────────────────────────
        if ($pedido->payment_status !== 'approved') {
            throw new InvalidPaymentStateException(
                $pedido->id,
                $pedido->payment_status ?? 'null',
                'approved',
            );
        }

        if (!$pedido->payment_gateway || $pedido->payment_gateway === 'manual') {
            throw new \InvalidArgumentException(
                "El pedido [{$pedido->folio}] usó pago manual. El reembolso debe procesarse bancariamente."
            );
        }

        if (!$pedido->gateway_payment_id) {
            throw new \InvalidArgumentException(
                "El pedido [{$pedido->folio}] no tiene gateway_payment_id. No se puede emitir reembolso."
            );
        }

        if (!$pedido->monto_pagado) {
            throw new \InvalidArgumentException(
                "El pedido [{$pedido->folio}] no registró monto_pagado. Estado inconsistente — posible fallo en webhook."
            );
        }

        // ── Saldo reembolsable ───────────────────────────────────────────────
        $montoPagado        = (float) $pedido->monto_pagado;
        $montoYaReembolsado = (float) ($pedido->monto_reembolsado ?? 0);
        $saldoReembolsable  = round($montoPagado - $montoYaReembolsado, 2);

        if ($saldoReembolsable <= 0) {
            throw new \InvalidArgumentException(
                "El pedido [{$pedido->folio}] ya fue reembolsado en su totalidad."
            );
        }

        $montoAReembolsar = $monto !== null ? round($monto, 2) : $saldoReembolsable;
        $esTotal          = abs($montoAReembolsar - $saldoReembolsable) < 0.01;

        if ($montoAReembolsar <= 0) {
            throw new \InvalidArgumentException('El monto del reembolso debe ser mayor a 0.');
        }

        if ($montoAReembolsar > $saldoReembolsable) {
            throw new \InvalidArgumentException(
                "El monto [{$montoAReembolsar}] excede el saldo reembolsable [{$saldoReembolsable}] del pedido [{$pedido->folio}]."
            );
        }

        // ── Llamada al gateway ───────────────────────────────────────────────
        $gatewayService = $this->paymentGatewayFactory->crear($pedido->payment_gateway);

        Log::info('[Refund] Emitiendo reembolso', [
            'pedido_id'          => $pedido->id,
            'folio'              => $pedido->folio,
            'gateway'            => $pedido->payment_gateway,
            'gateway_payment_id' => $pedido->gateway_payment_id,
            'monto'              => $montoAReembolsar,
            'tipo'               => $esTotal ? 'total' : 'parcial',
            'motivo'             => $motivo,
            'admin'              => $admin,
        ]);

        $respuesta = $gatewayService->emitirReembolso(
            $pedido->gateway_payment_id,
            $montoAReembolsar,
            $motivo,
        );

        // ── Persistir ────────────────────────────────────────────────────────
        return DB::transaction(function () use (
            $pedido, $montoAReembolsar, $esTotal, $motivo, $admin, $respuesta
        ) {
            $tipo = $esTotal ? 'reembolso' : 'reembolso_parcial';

            TransaccionPago::create([
                'pedido_id'          => $pedido->id,
                'tipo'               => $tipo,
                'gateway'            => $pedido->payment_gateway,
                'gateway_order_id'   => $pedido->gateway_order_id,
                'gateway_payment_id' => $respuesta->gatewayRefundId,
                'status'             => 'approved',
                'monto'              => $montoAReembolsar,
                'moneda'             => $pedido->moneda_cobro ?? 'MXN',
                'motivo'             => $motivo,
                'metadata'           => [
                    'admin'          => $admin,
                    'tipo'           => $tipo,
                    'payment_id_ref' => $pedido->gateway_payment_id,
                    'respuesta_raw'  => $respuesta->raw ?? null,
                ],
            ]);

            $nuevoMontoReembolsado = round(
                (float) ($pedido->monto_reembolsado ?? 0) + $montoAReembolsar,
                2,
            );

            $nuevoPaymentStatus = ($esTotal || $nuevoMontoReembolsado >= (float) $pedido->monto_pagado)
                ? 'refunded'
                : 'partial_refunded';

            $pedido->update([
                'monto_reembolsado' => $nuevoMontoReembolsado,
                'payment_status'    => $nuevoPaymentStatus,
            ]);

            $saldoPendiente = round((float) $pedido->monto_pagado - $nuevoMontoReembolsado, 2);

            Log::info('[Refund] Reembolso emitido exitosamente', [
                'pedido_id'               => $pedido->id,
                'folio'                   => $pedido->folio,
                'refund_id'               => $respuesta->gatewayRefundId,
                'monto'                   => $montoAReembolsar,
                'tipo'                    => $tipo,
                'monto_reembolsado_total' => $nuevoMontoReembolsado,
                'saldo_pendiente'         => $saldoPendiente,
                'nuevo_payment_status'    => $nuevoPaymentStatus,
            ]);

            return [
                'reembolso_id'            => $respuesta->gatewayRefundId,
                'monto'                   => $montoAReembolsar,
                'tipo'                    => $tipo,
                'gateway'                 => $pedido->payment_gateway,
                'pedido_id'               => $pedido->id,
                'folio'                   => $pedido->folio,
                'monto_reembolsado_total' => $nuevoMontoReembolsado,
                'saldo_pendiente'         => $saldoPendiente,
            ];
        });
    }

    // =========================================================================
    // REEMBOLSO AUTOMÁTICO (interno — usado por SuborderRegistrationService)
    // =========================================================================

    /**
     * Intenta emitir un reembolso parcial automático cuando falla stock en CHECK 2.
     * No lanza excepción — el fallo queda en log y marcado para revisión manual.
     */
    public function emitirAutomatico(Pedido $pedido, float $monto, string $motivo): void
    {
        if (!$pedido->payment_gateway || $pedido->payment_gateway === 'manual') {
            Log::warning('[Refund] Reembolso automático no aplicable — pago manual', [
                'pedido_id' => $pedido->id,
                'monto'     => $monto,
                'motivo'    => $motivo,
            ]);
            return;
        }

        try {
            $this->emitir($pedido, $monto, "[AUTO] {$motivo}");
        } catch (\Throwable $e) {
            Log::critical('[Refund] Reembolso automático FALLÓ — requiere acción manual URGENTE', [
                'pedido_id' => $pedido->id,
                'folio'     => $pedido->folio,
                'monto'     => $monto,
                'motivo'    => $motivo,
                'error'     => $e->getMessage(),
            ]);

            $pedido->update(['requiere_atencion_manual' => true]);
        }
    }
}