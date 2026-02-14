<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Data\Cva\ArticuloMinimoData;
use App\Data\Pedidos\PedidoData;
use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Services\Orders\OrchestratorOrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
     public function __construct(
        private OrchestratorOrdersService $orquestador
    ) {}

    public function store(PedidoData $request)
    {
        $pedido = $this->orquestador->crearPedido($request->productos->toArray());

        return response()->json($pedido, 201);
    }

    /**
     * Cotizar costo de envío de todos los proveedores sin crear pedido
     * 
     * @return JsonResponse
     */
   public function cotizarEnvio(PedidoData $request): JsonResponse
    {
        try {
            $cliente = Auth::user()->cliente;

            if (!$cliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aun no ha terminado de llenar los datos de envio'
                ], 422); // Un 422 (Unprocessable Entity) es más semántico aquí
            }

            // Importante: Pasa solo la parte de productos al orquestador
            $cotizacion = $this->orquestador->cotizarEnvioProductos(
                $request->productos->toArray(), 
                $cliente
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'total_envio' => $cotizacion['total_envio'],
                    'cotizaciones' => $cotizacion['cotizaciones'], 
                    // Eliminamos el desglose manual ya que es el mismo array
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al cotizar envío', [
                'request' => $request->toArray(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString() // Útil para debuggear en logs
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error al calcular costo de envío: ' . $e->getMessage() // Opcional: quitar el mensaje en producción
            ], 500);
        }
    }

    public function confirmarPedido(Pedido $pedido){
        $result = $this->orquestador->procesarPagoPedido($pedido);
        return response()->json($result,200);
    }
}
