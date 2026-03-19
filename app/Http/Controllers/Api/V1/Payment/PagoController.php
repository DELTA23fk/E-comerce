<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Data\Pedidos\PedidoData;
use App\Exceptions\InvalidPaymentStateException;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Services\Orders\OrchestratorOrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PagoController extends Controller
{
    public function __construct(
        private readonly OrchestratorOrdersService $orchestrator,
    ) {}

    /**
     * POST /pagos/iniciar
     * Inicia el flujo de pago con una pasarela externa.
     */
    public function iniciar(PedidoData $request): JsonResponse
    {
        try {
            $resultado = $this->orchestrator->realizarPedidoConPago(
                $request,
                $request->metodoPago->value,
            );

            return response()->json([
                'success'          => true,
                'redirect_url'     => $resultado['redirect_url'],
                'gateway_order_id' => $resultado['gateway_order_id'],
                'sandbox_url'      => $resultado['sandbox_url'] ?? null,
            ]);

        } catch (InvalidPaymentStateException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);

        } catch (PaymentGatewayException $e) {
            Log::error('[PagoController] Error al iniciar pago', [
                'gateway' => $request->metodoPago->value,  // ← antes usaba $pedido->id y $data que no existen
                'error'   => $e->getMessage(),
                'context' => $e->context,                  // pedido_id viene en el context si el pedido sí se creó
            ]);
            return response()->json([
                'success'   => false,
                'message'   => 'No se pudo conectar con la pasarela de pago.',
                'pedido_id' => $e->context['pedido_id'] ?? null, // para reintentar si el pedido sí se creó
            ], 502);
        }
    }

    /**
     * POST /pagos/iniciar-manual
     * Registra intención de pago sin gateway externo.
     */
    public function iniciarManual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pedido_id'   => ['required', 'integer', 'exists:pedidos,id'],
            'metodo_pago' => ['nullable', 'string', Rule::in(['transferencia', 'deposito'])],
        ]);

        $pedido = Pedido::findOrFail($data['pedido_id']);

        if ($pedido->cliente_id !== $request->user()->cliente?->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        try {
            $resultado = $this->orchestrator->iniciarPagoManual(
                $pedido,
                $data['metodo_pago'] ?? 'transferencia',
            );

            return response()->json(['success' => true, 'data' => $resultado]);

        } catch (InvalidPaymentStateException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /pagos/resultado
     * Página de retorno después de que el usuario paga (back_url de MercadoPago).
     * El estado REAL llega por webhook; esta URL solo sirve para UX.
     */
    public function resultado(Request $request): JsonResponse
    {
        $folio  = $request->query('folio');
        $status = $request->query('status', 'pending');

        $pedido = $folio
            ? Pedido::where('folio', $folio)->first()
            : null;

        // Verificar autorización solo si hay sesión activa
        // MP puede redirigir sin que el usuario esté autenticado en tu app
        if ($pedido && $request->user() && $pedido->cliente_id !== $request->user()->cliente?->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return response()->json([
            'folio'          => $folio,
            'status_gateway' => $status,
            // El estado REAL viene de la BD — no del status del query string
            // porque MP puede redirigir a success antes de que el webhook llegue
            'payment_status' => $pedido?->payment_status ?? 'pending',
            'estatus_pedido' => $pedido?->estatus        ?? 'desconocido',
            'mensaje'        => match ($status) {
                'success' => 'Pago recibido. Tu pedido se está procesando.',
                'failure' => 'El pago no pudo completarse. Intenta nuevamente.',
                'pending' => 'Tu pago está pendiente de confirmación.',
                default   => 'Estado desconocido.',
            },
        ]);
    }
}
