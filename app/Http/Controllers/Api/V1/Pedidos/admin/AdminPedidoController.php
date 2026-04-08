<?php

namespace App\Http\Controllers\Api\V1\Pedidos\admin;

use App\Data\Pedidos\PedidoFiltrosDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pedido\ActualizarEstatusPedidoRequest;
use App\Http\Requests\Admin\Pedido\ActualizarPedidoProveedorRequest;
use App\Http\Requests\Admin\Pedido\ActualizarFechaEntregaMasivaRequest;
use App\Http\Requests\Admin\Pedido\ListarPedidosAdminRequest;
use App\Http\Resources\Admin\PedidoAdminCollection;
use App\Http\Resources\Admin\PedidoAdminDetalleResource;
use App\Http\Resources\Admin\PedidoProveedorResource;
use App\Services\Pedidos\admin\PedidoService;
use Illuminate\Http\JsonResponse;

class AdminPedidoController extends Controller
{
    public function __construct(private readonly PedidoService $pedidoService) {}

    /**
     * GET /api/admin/pedidos
     *
     * Listado paginado global con filtros avanzados.
     */
    public function index(ListarPedidosAdminRequest $request): JsonResponse
    {
        $filtros = PedidoFiltrosDTO::fromArray($request->validated());

        $pedidos = $this->pedidoService->listarPedidos($filtros);

        return response()->json(new PedidoAdminCollection($pedidos));
    }

    /**
     * GET /api/admin/pedidos/{id}
     *
     * Detalle completo del pedido: cliente, detalles, proveedores, transacciones.
     */
    public function show(int $id): JsonResponse
    {
        $pedido = $this->pedidoService->verDetallePedido($id);

        return response()->json([
            'data' => new PedidoAdminDetalleResource($pedido),
        ]);
    }

    /**
     * PATCH /api/admin/pedidos/{id}/estatus
     *
     * Actualiza el estatus del pedido maestro con validación de transición.
     */
    public function actualizarEstatus(ActualizarEstatusPedidoRequest $request, int $id): JsonResponse
    {
        $pedido = $this->pedidoService->actualizarEstatusPedido(
            pedidoId:      $id,
            nuevoEstatus:  $request->validated('estatus'),
            observaciones: $request->validated('observaciones'),
        );

        return response()->json([
            'message' => 'Estatus actualizado correctamente.',
            'data'    => new PedidoAdminDetalleResource($pedido),
        ]);
    }

    /**
     * GET /api/admin/pedidos/atencion-requerida
     *
     * Pedidos con flag de atención manual activo (maestro o proveedor).
     */
    public function atencionRequerida(): JsonResponse
    {
        $pedidos = $this->pedidoService->pedidosConAtencionRequerida();

        return response()->json([
            'data'  => PedidoAdminDetalleResource::collection($pedidos),
            'total' => $pedidos->count(),
        ]);
    }

    /**
     * GET /api/admin/pedidos/atascados
     *
     * Pedidos pagados sin avanzar en las últimas N horas.
     */
    public function atascados(int $horas = 24): JsonResponse
    {
        $pedidos = $this->pedidoService->pedidosAtascados($horas);

        return response()->json([
            'data'           => PedidoAdminDetalleResource::collection($pedidos),
            'total'          => $pedidos->count(),
            'horas_criterio' => $horas,
        ]);
    }

    /**
     * GET /api/admin/pedidos/dashboard
     *
     * Métricas generales, proveedores, tendencia mensual y top clientes.
     */
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'data' => [
                'estadisticas' => $this->pedidoService->estadisticasGenerales(),
                'proveedores'  => $this->pedidoService->estadisticasProveedores(),
                'tendencia'    => $this->pedidoService->tendenciaMensual(6),
                'top_clientes' => $this->pedidoService->topClientes(10),
            ],
        ]);
    }

    /**
     * GET /api/admin/pedidos/tendencia
     *
     * Serie mensual configurable (query param: meses, default 12).
     */
    public function tendencia(int $meses = 12): JsonResponse
    {
        $meses = min(max($meses, 1), 36); // entre 1 y 36 meses

        return response()->json([
            'data'  => $this->pedidoService->tendenciaMensual($meses),
            'meses' => $meses,
        ]);
    }

    /**
     * GET /api/admin/pedidos/top-clientes
     *
     * Ranking de clientes por gasto (query param: limite, default 10).
     */
    public function topClientes(int $limite = 10): JsonResponse
    {
        $limite = min(max($limite, 1), 50);

        return response()->json([
            'data'   => $this->pedidoService->topClientes($limite),
            'limite' => $limite,
        ]);
    }
}