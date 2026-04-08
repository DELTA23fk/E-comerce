<?php

namespace App\Http\Controllers\Api\V1\Pedidos\client;

use App\Data\Pedidos\PedidoFiltrosDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cliente\Pedido\ListarPedidosRequest;
use App\Http\Resources\Cliente\PedidoCollection;
use App\Http\Resources\Cliente\PedidoDetalleResource;
use App\Http\Resources\Cliente\PedidoResumenResource;
use App\Http\Resources\Cliente\SeguimientoEnvioResource;
use App\Http\Resources\Cliente\TransaccionCollection;
use App\Services\Pedidos\client\PedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientePedidoController extends Controller
{
    public function __construct(private readonly PedidoService $pedidoService) {}

    /**
     * GET /api/cliente/pedidos
     *
     * Lista paginada de pedidos del cliente autenticado con filtros opcionales.
     */
    public function index(ListarPedidosRequest $request): JsonResponse
    {
        $cliente = $request->user()->cliente;
        $filtros = PedidoFiltrosDTO::fromArray($request->validated());

        $pedidos = $this->pedidoService->listarPedidos($cliente, $filtros);

        return response()->json(new PedidoCollection($pedidos));
    }

    /**
     * GET /api/cliente/pedidos/{id}
     *
     * Detalle completo de un pedido: detalles, proveedores, transacciones.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $cliente = $request->user()->cliente;

        $pedido = $this->pedidoService->verDetallePedido($cliente, $id);

        return response()->json([
            'data' => new PedidoDetalleResource($pedido),
        ]);
    }

    /**
     * GET /api/cliente/pedidos/{id}/resumen
     *
     * Vista ejecutiva del pedido: estatus, fechas estimadas, totales.
     */
    public function resumen(int $id, Request $request): JsonResponse
    {
        $cliente = $request->user()->cliente;

        $resumen = $this->pedidoService->resumenPedido($cliente, $id);

        return response()->json([
            'data' => new PedidoResumenResource((object) $resumen),
        ]);
    }

    /**
     * GET /api/cliente/pedidos/{id}/seguimiento
     *
     * Estado de envío por proveedor con fecha estimada de entrega.
     */
    public function seguimiento(int $id, Request $request): JsonResponse
    {
        $cliente = $request->user()->cliente;

        $seguimiento = $this->pedidoService->seguimientoEnvio($cliente, $id);

        return response()->json([
            'data' => new SeguimientoEnvioResource((object) $seguimiento),
        ]);
    }

    /**
     * GET /api/cliente/pedidos/{id}/transacciones
     *
     * Historial de transacciones de pago de un pedido.
     */
    public function transacciones(int $id, Request $request): JsonResponse
    {
        $cliente = $request->user()->cliente;

        $transacciones = $this->pedidoService->transaccionesPedido($cliente, $id);
        $pedido = [
            'fecha_pago' => $transacciones->fecha_pago,
            'estatus' => $transacciones->estatus,
            'gateway' => $transacciones->payment_gateway,
        ];

        $transaccionesCollection = $transacciones->transaccionesPagos->toArray();

        $data = array_map(function($transaccion) use ($pedido) {
            return array_merge($pedido, [
                'monto' => $transaccion['monto'],
            ]);
        }, $transaccionesCollection);

        return response()->json([
            'data' => TransaccionCollection::make($data),
        ]);
    }

    /**
     * GET /api/cliente/pedidos/estadisticas
     *
     * Estadísticas personales del cliente: gasto total, ticket promedio, por estatus.
     */
    public function estadisticas(Request $request): JsonResponse
    {
        $cliente = $request->user()->cliente;

        $stats = $this->pedidoService->estadisticasCliente($cliente);

        return response()->json([
            'data' => $stats,
        ]);
    }
}