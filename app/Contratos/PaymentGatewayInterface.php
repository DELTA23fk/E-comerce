<?php
namespace App\Contratos;

use App\Data\Payment\PaymentStatusData;
use App\Data\Payment\PreferenceResponseData;
use App\Data\Payment\ReembolsoData;
use App\Data\Payment\WebhookResultData;
use App\Models\Pedido;

/**
 * Contrato del patrón Strategy para pasarelas de pago.
 *
 * Cada gateway (MercadoPago, PayPal, Stripe…) implementa esta interfaz.
 * El orquestador solo conoce este contrato, nunca la implementación concreta.
 */
interface PaymentGatewayInterface
{
    /**
     * Crea una preferencia/orden de pago en el gateway externo.
     *
     * MercadoPago → crea una Preference y devuelve el init_point (redirect URL).
     * PayPal      → crea una Order y devuelve el approve_link.
     *
     * @throws \App\Exceptions\Payments\PaymentGatewayException
     */
    public function crearPreferencia(Pedido $pedido): PreferenceResponseData;

    /**
     * Consulta el estado actual de un pago por su ID en el gateway.
     *
     * @param  string  $gatewayPaymentId  payment_id (MP) | capture_id (PayPal)
     *
     * @throws \App\Exceptions\Payments\PaymentGatewayException
     */
    public function verificarPago(string $gatewayPaymentId): PaymentStatusData;

    /**
     * Procesa y valida un webhook entrante del gateway.
     *
     * Responsabilidades del gateway:
     *  - Verificar la firma/autenticidad del payload.
     *  - Consultar la API para obtener el estado definitivo (no confiar solo en el webhook).
     *  - Retornar datos normalizados.
     *
     * @param  array  $payload  Cuerpo del webhook ya decodificado.
     * @param  array  $headers  Cabeceras HTTP originales para verificar firma.
     *
     * @throws \App\Exceptions\Payments\PaymentWebhookException
     */
    public function procesarWebhook(array $payload, array $headers): WebhookResultData;

    /**
     * Emite un reembolso (total o parcial) sobre un pago aprobado.
     *
     * @param  string  $gatewayPaymentId  ID del pago a reembolsar.
     * @param  float   $monto             Monto a reembolsar. Si es igual al total → reembolso total.
     * @param  string  $motivo            Razón del reembolso (para auditoría).
     *
     * @throws \App\Exceptions\Payments\PaymentGatewayException
     */
    public function emitirReembolso(string $gatewayPaymentId, float $monto, string $motivo): ReembolsoData;

    /**
     * Nombre identificador del gateway (snake_case).
     * Ej: 'mercadopago', 'paypal'.
     */
    public function getNombre(): string;
}