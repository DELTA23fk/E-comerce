<?php

namespace App\Services\Orders;

use App\Data\Payment\PreferenceResponseData;
use App\Enum\Order\OrderStatusEnum;
use App\Enum\Order\PaymentStatusEnum;
use App\Exceptions\InvalidPaymentStateException;
use App\Exceptions\PaymentGatewayException;
use App\Factories\PaymentGatewayFactory;
use App\Models\Pedido;
use App\Models\TransaccionPago;
use Faker\Provider\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
 
/**
 * Responsabilidad única: iniciar el flujo de pago después de crear el pedido.
 *
 * - Crea la preferencia / orden en el gateway externo.
 * - Registra la transacción inicial en transacciones_pagos.
 * - Maneja pago manual (transferencia, depósito, OXXO, etc.).
 */
class PaymentFlowService
{
    public function __construct(
        private readonly PaymentGatewayFactory $paymentGatewayFactory,
    ) {}
 
    // =========================================================================
    // PAGO CON GATEWAY
    // =========================================================================
 
    /**
     * @return array { redirect_url, gateway_order_id, sandbox_url }
     * @throws InvalidPaymentStateException
     * @throws PaymentGatewayException
     */
    public function iniciarPago(Pedido $pedido, string $gateway): array
    {
        if ($pedido->estatus !== OrderStatusEnum::PENDIENTE_PAGO->value) {
            throw new InvalidPaymentStateException($pedido->id, $pedido->estatus, 'pendiente_pago');
        }
 
        if (!in_array($pedido->payment_status, [PaymentStatusEnum::PENDING->value, PaymentStatusEnum::IN_PROCESS->value, null], true)) {
            throw new InvalidPaymentStateException($pedido->id, $pedido->payment_status ?? 'null', PaymentStatusEnum::PENDING->value);
        }
 
        $gatewayService = $this->paymentGatewayFactory->crear($gateway);
 
        $pedido->loadMissing('detalles.producto', 'cliente.user');
 
        Log::info('[PaymentFlow] Iniciando pago', [
            'pedido_id' => $pedido->id,
            'folio'     => $pedido->folio,
            'gateway'   => $gateway,
            'total'     => $pedido->precio_total,
        ]);
 
        $preferencia = $gatewayService->crearPreferencia($pedido);
 
        $pedido->update([
            'payment_gateway'  => $gateway,
            'gateway_order_id' => $preferencia->gatewayOrderId,
            'payment_status'   => PaymentStatusEnum::IN_PROCESS->value,
        ]);
 
        TransaccionPago::create([
            'pedido_id'          => $pedido->id,
            'tipo'               => 'pago',
            'gateway'            => $gateway,
            'gateway_order_id'   => $preferencia->gatewayOrderId,
            'gateway_payment_id' => $preferencia->gatewayOrderId,
            'status'             => PaymentStatusEnum::IN_PROCESS->value,
            'monto'              => $pedido->precio_total,
            'moneda'             => $pedido->moneda_cobro ?? 'MXN',
            'metadata'           => $preferencia->extra,
            'ip_cliente'         => Request::ip(),
            'user_agent'         => Request::userAgent(),
        ]);
 
        Log::info('[PaymentFlow] Preferencia creada', [
            'pedido_id'        => $pedido->id,
            'gateway_order_id' => $preferencia->gatewayOrderId,
        ]);
 
        return [
            'redirect_url'     => $this->resolverRedirectUrl($preferencia, $gateway),
            'gateway_order_id' => $preferencia->gatewayOrderId,
            'sandbox_url'      => $preferencia->sandboxUrl ?? null,
        ];
    }
 
    // =========================================================================
    // PAGO MANUAL
    // =========================================================================
 
    /**
     * @return array { folio, total, moneda, metodo_pago, instrucciones, vencimiento, referencia }
     * @throws InvalidPaymentStateException
     */
    public function iniciarPagoManual(Pedido $pedido, string $metodoPago = 'transferencia'): array
    {
        if ($pedido->estatus !== 'pendiente_pago') {
            throw new InvalidPaymentStateException($pedido->id, $pedido->estatus, 'pendiente_pago');
        }
 
        $vencimiento = now()->addDays(config('payments.pago_manual.dias_vencimiento', 3));
 
        $pedido->update([
            'payment_gateway' => 'manual',
            'monto_pagado'    => $pedido->precio_total,
            'moneda_cobro'    => $pedido->moneda_cobro ?? 'MXN',
            'payment_status'  => 'pending',
        ]);
 
        TransaccionPago::create([
            'pedido_id'          => $pedido->id,
            'tipo'               => 'pago',
            'gateway'            => 'manual',
            'gateway_payment_id' => 'MANUAL_' . $pedido->folio,
            'status'             => 'pending',
            'monto'              => $pedido->precio_total,
            'moneda'             => $pedido->moneda_cobro ?? 'MXN',
            'metadata'           => [
                'metodo_pago' => $metodoPago,
                'vencimiento' => $vencimiento->toDateTimeString(),
            ],
            'ip_cliente' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
 
        Log::info('[PaymentFlow] Pago manual registrado', [
            'pedido_id'   => $pedido->id,
            'folio'       => $pedido->folio,
            'metodo_pago' => $metodoPago,
            'vencimiento' => $vencimiento,
        ]);
 
        $instrucciones = config("payments.pago_manual.instrucciones.{$metodoPago}", [
            'banco'   => config('payments.pago_manual.banco',   'BBVA'),
            'cuenta'  => config('payments.pago_manual.cuenta',  ''),
            'clabe'   => config('payments.pago_manual.clabe',   ''),
            'titular' => config('payments.pago_manual.titular', config('app.name')),
        ]);
 
        return [
            'folio'         => $pedido->folio,
            'total'         => $pedido->precio_total,
            'moneda'        => $pedido->moneda_cobro ?? 'MXN',
            'metodo_pago'   => $metodoPago,
            'instrucciones' => $instrucciones,
            'vencimiento'   => $vencimiento->toDateTimeString(),
            'referencia'    => $pedido->folio,
        ];
    }
 
    // =========================================================================
    // PRIVADOS
    // =========================================================================
 
    private function resolverRedirectUrl(PreferenceResponseData $preferencia, string $gateway): string
    {
        if ($gateway === 'mercadopago' && config('app.env') !== 'production' && $preferencia->sandboxUrl) {
            return $preferencia->sandboxUrl;
        }
 
        return $preferencia->redirectUrl;
    }
}