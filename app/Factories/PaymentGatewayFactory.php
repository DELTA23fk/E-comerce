<?php

namespace App\Factories;

use App\Contratos\PaymentGatewayInterface;
use App\Services\Payments\MercadoPagoGateway;
use App\Services\Payments\PayPalGateway;
use InvalidArgumentException;

/**
 * Factory del patrón Factory para pasarelas de pago.
 *
 * Crea la instancia concreta del gateway solicitado usando la config de payments.php.
 * El orquestador y los controllers solo usan este factory, nunca instancian gateways directamente.
 *
 * Registro centralizado: para agregar un nuevo gateway basta añadirlo aquí y en config/payments.php.
 */
class PaymentGatewayFactory
{
    /**
     * Mapa de nombre → clase concreta.
     * Extender aquí para nuevos gateways.
     */
    private const GATEWAYS = [
        'mercadopago' => MercadoPagoGateway::class,
        'paypal'      => PayPalGateway::class,
    ];

    /**
     * Crea el gateway solicitado con su configuración correspondiente.
     *
     * @param  string  $nombre  'mercadopago' | 'paypal'
     *
     * @throws InvalidArgumentException  Si el gateway no está registrado o su config es inválida.
     */
    public function crear(string $nombre): PaymentGatewayInterface
    {
        $nombre = strtolower($nombre);

        if (!array_key_exists($nombre, self::GATEWAYS)) {
            throw new InvalidArgumentException(
                "Gateway de pago '{$nombre}' no registrado. Disponibles: " . implode(', ', array_keys(self::GATEWAYS))
            );
        }

        return match ($nombre) {
            'mercadopago' => $this->crearMercadoPago(),
            'paypal'      => $this->crearPayPal(),
            default       => throw new InvalidArgumentException("Gateway '{$nombre}' no implementado."),
        };
    }

    /**
     * Lista los gateways activos según la configuración.
     *
     * @return string[]
     */
    public function gatewaysActivos(): array
    {
        return array_keys(array_filter(
            config('payments.gateways', []),
            fn($cfg) => $cfg['activo'] ?? false,
        ));
    }

    // =========================================================================
    // BUILDERS PRIVADOS
    // =========================================================================

    private function crearMercadoPago(): MercadoPagoGateway
    {
        $config = config('payments.gateways.mercadopago');

        // Solo access_token es obligatorio.
        // webhook_secret es opcional: si no está configurado, la validación
        // de firma se omite en sandbox y emite warning en producción.
        $this->validarConfig('mercadopago', $config, ['access_token']);

        return new MercadoPagoGateway(
            accessToken:   $config['access_token'],
            sandbox:       $config['sandbox'] ?? (config('app.env') !== 'production'),
            webhookSecret: $config['webhook_secret'] ?? null,
        );
    }

    private function crearPayPal(): PayPalGateway
    {
        $config = config('payments.gateways.paypal');

        $this->validarConfig('paypal', $config, ['client_id', 'client_secret', 'webhook_id']);

        return new PayPalGateway(
            clientId:     $config['client_id'],
            clientSecret: $config['client_secret'],
            webhookId:    $config['webhook_id'],
            sandbox:      $config['sandbox'] ?? (config('app.env') !== 'production'),
        );
    }

    private function validarConfig(string $gateway, ?array $config, array $required): void
    {
        if (empty($config)) {
            throw new InvalidArgumentException("Config para gateway '{$gateway}' no encontrada en payments.php.");
        }

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException(
                    "Config '{$key}' requerida para gateway '{$gateway}' está vacía."
                );
            }
        }
    }
}