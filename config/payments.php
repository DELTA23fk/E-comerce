<?php
/**
 * Configuración de pasarelas de pago.
 *
 * Variables de entorno necesarias en .env:
 *
 * # MercadoPago
 * MP_ACCESS_TOKEN=APP_USR-xxxx
 * MP_WEBHOOK_SECRET=xxxx
 * MP_SANDBOX=true
 *
 * # PayPal
 * PAYPAL_CLIENT_ID=xxxx
 * PAYPAL_CLIENT_SECRET=xxxx
 * PAYPAL_WEBHOOK_ID=xxxx
 * PAYPAL_SANDBOX=true
 *
 * # Pago manual
 * PAGO_MANUAL_BANCO=BBVA
 * PAGO_MANUAL_CUENTA=0123456789
 * PAGO_MANUAL_CLABE=012345678901234567
 * PAGO_MANUAL_TITULAR="Mi Empresa SA de CV"
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway por defecto
    |--------------------------------------------------------------------------
    | Si el cliente no elige gateway explícitamente, se usa este.
    | Valores: 'mercadopago' | 'paypal' | 'manual'
    */
    'default' => env('PAYMENT_GATEWAY_DEFAULT', 'mercadopago'),

    /*
    |--------------------------------------------------------------------------
    | Gateways activos
    |--------------------------------------------------------------------------
    */
    'gateways' => [

        'mercadopago' => [
            'activo'         => env('MP_ACTIVO', true),
            'access_token'   => env('MP_ACCESS_TOKEN'),
            'webhook_secret' => env('MP_WEBHOOK_SECRET'),
            'sandbox'        => env('MP_SANDBOX', true),
            // URL pública que MercadoPago llama con el webhook (se sobreescribe en crearPreferencia)
            'webhook_url'    => env('MP_WEBHOOK_URL', env('APP_URL') . '/webhooks/mercadopago'),
        ],

        'paypal' => [
            'activo'         => env('PAYPAL_ACTIVO', false),
            'client_id'      => env('PAYPAL_CLIENT_ID'),
            'client_secret'  => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id'     => env('PAYPAL_WEBHOOK_ID'),
            'sandbox'        => env('PAYPAL_SANDBOX', true),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Pago manual (transferencia / depósito)
    |--------------------------------------------------------------------------
    */
    'pago_manual' => [
        'activo'           => env('PAGO_MANUAL_ACTIVO', true),
        'dias_vencimiento' => 3,
        'banco'            => env('PAGO_MANUAL_BANCO',   'BBVA'),
        'cuenta'           => env('PAGO_MANUAL_CUENTA',  ''),
        'clabe'            => env('PAGO_MANUAL_CLABE',   ''),
        'titular'          => env('PAGO_MANUAL_TITULAR', env('APP_NAME', 'Tienda')),

        'instrucciones' => [
            'transferencia' => [
                'descripcion' => 'Realiza una transferencia SPEI a la siguiente cuenta:',
                'banco'       => env('PAGO_MANUAL_BANCO',  'BBVA'),
                'clabe'       => env('PAGO_MANUAL_CLABE',  ''),
                'titular'     => env('PAGO_MANUAL_TITULAR', env('APP_NAME')),
            ],
            'deposito' => [
                'descripcion' => 'Realiza un depósito en cualquier sucursal:',
                'banco'       => env('PAGO_MANUAL_BANCO',   'BBVA'),
                'cuenta'      => env('PAGO_MANUAL_CUENTA',  ''),
                'titular'     => env('PAGO_MANUAL_TITULAR', env('APP_NAME')),
            ],
        ],
    ],

];