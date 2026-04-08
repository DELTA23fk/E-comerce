<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Data\Pedidos\PedidoData;
use App\Exceptions\InvalidPaymentStateException;
use App\Exceptions\Orders\ClientProfileNotFoundException;
use App\Exceptions\Orders\InsufficientStockException;
use App\Exceptions\Orders\InvalidOrderStateException;
use App\Exceptions\Orders\ProductNotFoundException;
use App\Exceptions\Orders\ShippingOutOfRangeException;
use App\Exceptions\Orders\ShippingQuoteException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Services\Orders\OrchestratorOrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
     public function __construct(
        private OrchestratorOrdersService $orquestador
    ) {}

    /**
     * Crea el pedido maestro y devuelve el redirect de pago.
     * Valida stock (CHECK 1) y cotiza envío antes de persistir.
     */
    public function store(PedidoData $request): JsonResponse
    {
        try {
            $resultado = $this->orquestador->realizarPedidoConPago(
                $request,
                $request->metodoPago->value,
            );
 
           return response()->json([
                'success'          => true,
                'redirect_url'     => $resultado['redirect_url'],
                'gateway_order_id' => $resultado['gateway_order_id'],
                'sandbox_url'      => $resultado['sandbox_url'] ?? null,
            ]);
 
        } catch (ClientProfileNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Tu perfil de cliente no está completo. Actualiza tus datos antes de realizar un pedido.',
            ], 404);
 
        } catch (ProductNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Uno o más productos no se encontraron.',
                'detalle' => ['codigo' => $e->getMessage()],
            ], 422);
 
        } catch (InsufficientStockException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'No hay suficiente stock para completar el pedido.',
                'detalle' => $e->toArray(),
            ], 422);
 
        } catch (ShippingOutOfRangeException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'No se puede realizar envío a tu dirección.',
                'detalle' => $e->getMessage(),
            ], 422);
 
        } catch (ShippingQuoteException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'No se pudo cotizar el envío. Intenta de nuevo.',
                'detalle' => $e->getMessage(),
            ], 422);
 
        } catch (PaymentGatewayException $e) {
            // El pedido fue creado pero el gateway falló.
            // Se devuelve pedido_id para que el frontend permita reintentar el pago.
            return response()->json([
                'success'  => false,
                'error'    => 'El pedido fue generado pero ocurrió un error al iniciar el pago. Puedes reintentar.',
                'detalle'  => $e->getMessage(),
            ], 502);
 
        } catch (\Throwable $e) {
            Log::error('[OrderController] Error inesperado en store', [
                'user_id' => Auth::id(),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
 
            return response()->json([
                'success' => false,
                'error'   => 'Ocurrió un error inesperado. Por favor intenta de nuevo.',
            ], 500);
        }
    }

    /**
     * Cotiza el costo de envío de una lista de productos sin crear pedido.
     * Útil para mostrar el costo de envío en el carrito antes del checkout.
     */
    public function cotizarEnvio(PedidoData $request): JsonResponse
    {
        try {
            $cliente = Auth::user()?->cliente;
 
            if (!$cliente) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Tu perfil de cliente no está completo. Actualiza tus datos antes de cotizar.',
                ], 404);
            }
 
            $cotizacion = $this->orquestador->cotizarEnvioProductos(
                $request->productos->toArray(),
                $cliente,
            );
 
            return response()->json([
                'success' => true,
                'data'    => [
                    'cotizaciones_por_proveedor' => $cotizacion['cotizaciones'],
                    'total_envio'                => $cotizacion['total_envio'],
                    'proveedores_involucrados'   => count($cotizacion['cotizaciones']),
                ],
                'advertencias' => $cotizacion['errores_proveedores'] ?? [],
            ]);
 
        } catch (ProductNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Uno o más productos no se encontraron.',
                'detalle' => ['codigo' => $e->getMessage()],
            ], 422);
 
        } catch (ShippingOutOfRangeException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'No se puede realizar envío a tu dirección.',
                'detalle' => $e->getMessage(),
            ], 422);
 
        } catch (ShippingQuoteException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'No se pudo cotizar el envío con ningún proveedor disponible.',
            ], 422);
 
        } catch (\Throwable $e) {
            Log::error('[OrderController] Error inesperado en cotizarEnvio', [
                'user_id' => Auth::id(),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
 
            return response()->json([
                'success' => false,
                'error'   => 'Error al calcular el costo de envío.',
            ], 500);
        }
    }


     /**
     * Inicia o reintenta el pago de un pedido ya creado.
     * Útil cuando el gateway falló en store() y el cliente quiere reintentar.
     */
    public function iniciarPago(Pedido $pedido): JsonResponse
    {
        // $this->authorize('pay', $pedido);
 
        try {
            $resultado = $this->orquestador->iniciarPago($pedido, 'mercadopago');
 
            return response()->json([
                'success' => true,
                'data'    => $resultado,
            ]);
 
        } catch (InvalidPaymentStateException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'El pedido no está en un estado válido para iniciar el pago.',
                'detalle' => ['estado_actual' => $pedido->estatus],
            ], 409);
 
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Error al conectar con la pasarela de pago. Intenta de nuevo.',
                'detalle' => $e->getMessage(),
            ], 502);
 
        } catch (\Throwable $e) {
            Log::error('[OrderController] Error inesperado en iniciarPago', [
                'pedido_id' => $pedido->id,
                'error'     => $e->getMessage(),
            ]);
 
            return response()->json([
                'success' => false,
                'error'   => 'Ocurrió un error inesperado. Por favor intenta de nuevo.',
            ], 500);
        }
    }

        /**
     * Registra intención de pago manual (transferencia, depósito, etc.)
     * y devuelve las instrucciones bancarias al cliente.
     */
    // public function iniciarPagoManual(Pedido $pedido): JsonResponse
    // {
    //     $this->authorize('pay', $pedido);
 
    //     try {
    //         $resultado = $this->orquestador->iniciarPagoManual($pedido);
 
    //         return response()->json([
    //             'success' => true,
    //             'data'    => $resultado,
    //         ]);
 
    //     } catch (InvalidPaymentStateException $e) {
    //         return response()->json([
    //             'success' => false,
    //             'error'   => 'El pedido no está en un estado válido para registrar pago manual.',
    //             'detalle' => ['estado_actual' => $pedido->estatus],
    //         ], 409);
 
    //     } catch (\Throwable $e) {
    //         Log::error('[OrderController] Error inesperado en iniciarPagoManual', [
    //             'pedido_id' => $pedido->id,
    //             'error'     => $e->getMessage(),
    //         ]);
 
    //         return response()->json([
    //             'success' => false,
    //             'error'   => 'Ocurrió un error inesperado. Por favor intenta de nuevo.',
    //         ], 500);
    //     }
    // }

     /**
     * Fuerza el procesamiento de subpedidos de un pedido ya pagado.
     * Normalmente lo dispara el webhook, pero también puede usarse desde
     * un panel admin para reintentar pedidos con payment_status = approved
     * que no fueron procesados correctamente.
     */
    public function confirmarPedido(Pedido $pedido): JsonResponse
    {
        try {
            $resultado = $this->orquestador->procesarPagoPedido($pedido);
 
            return response()->json([
                'success' => true,
                'data'    => ['procesado' => $resultado],
            ]);
 
        } catch (InvalidOrderStateException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'El pedido no está en estado pendiente_pago.',
                'detalle' => ['estado_actual' => $pedido->estatus],
            ], 409);
 
        } catch (\Throwable $e) {
            Log::error('[OrderController] Error inesperado en confirmarPedido', [
                'pedido_id' => $pedido->id,
                'error'     => $e->getMessage(),
            ]);
 
            return response()->json([
                'success' => false,
                'error'   => 'Ocurrió un error inesperado al confirmar el pedido.',
            ], 500);
        }
    }

    /**
     * Emite un reembolso manual parcial o total contra el gateway de pago.
     *
     * Body JSON:
     *   { "monto": 150.00, "motivo": "Producto dañado" }   ← parcial
     *   { "motivo": "Cancelación" }                         ← total (sin monto)
     */
    public function reembolsar(Pedido $pedido): JsonResponse
    {
        // $this->authorize('refund', $pedido);
 
        $validated = request()->validate([
            'monto'  => ['nullable', 'numeric', 'min:0.01'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);
 
        try {
            $resultado = $this->orquestador->emitirReembolso(
                pedido: $pedido,
                monto:  $validated['monto'] ?? null,
                motivo: $validated['motivo'],
                admin:  Auth::user()?->name ?? Auth::id(),
            );
 
            return response()->json([
                'success' => true,
                'data'    => $resultado,
            ]);
 
        } catch (InvalidPaymentStateException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'El pedido no tiene un pago aprobado. No se puede emitir reembolso.',
                'detalle' => ['payment_status' => $pedido->payment_status],
            ], 409);
 
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 422);
 
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Error al procesar el reembolso con la pasarela de pago.',
                'detalle' => $e->getMessage(),
            ], 502);
 
        } catch (\Throwable $e) {
            Log::error('[OrderController] Error inesperado en reembolsar', [
                'pedido_id' => $pedido->id,
                'error'     => $e->getMessage(),
            ]);
 
            return response()->json([
                'success' => false,
                'error'   => 'Ocurrió un error inesperado al procesar el reembolso.',
            ], 500);
        }
    }
}
