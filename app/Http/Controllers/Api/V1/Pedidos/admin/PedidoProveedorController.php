<?php

namespace App\Http\Controllers\Api\V1\Pedidos\admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pedido\ActualizarPedidoProveedorRequest;
use App\Http\Requests\Admin\Pedido\ActualizarFechaEntregaMasivaRequest;
use App\Http\Resources\Admin\PedidoProveedorResource;
use App\Services\Pedidos\admin\PedidoService;
use Illuminate\Http\JsonResponse;

class PedidoProveedorController extends Controller
{
    public function __construct(private readonly PedidoService $pedidoService) {}

    /**
     * PATCH /api/admin/pedidos-proveedor/{id}
     *
     * Actualiza status, fecha de entrega estimada, emails y origen de envío
     * de un pedido proveedor. El pedido maestro se sincroniza automáticamente.
     */
    public function actualizar(ActualizarPedidoProveedorRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();

        $pp = $this->pedidoService->actualizarPedidoProveedor(
            pedidoProveedorId:    $id,
            nuevoStatus:          $data['status']                 ?? null,
            fechaEntregaEstimada: $data['fecha_entrega_estimada'] ?? null,
            emailAgente:          $data['email_agente']           ?? null,
            emailAlmacen:         $data['email_almacen']          ?? null,
            origenEnvio:          $data['origen_envio']           ?? null,
        );

        return response()->json([
            'message' => 'Pedido proveedor actualizado correctamente.',
            'data'    => new PedidoProveedorResource($pp),
        ]);
    }

    /**
     * PATCH /api/admin/pedidos-proveedor/fecha-entrega-masiva
     *
     * Aplica la misma fecha de entrega estimada a múltiples pedidos proveedor.
     */
    public function actualizarFechaMasiva(ActualizarFechaEntregaMasivaRequest $request): JsonResponse
    {
        $data = $request->validated();

        $actualizados = $this->pedidoService->actualizarFechaEntregaMasiva(
            ids:   $data['ids'],
            fecha: $data['fecha_entrega_estimada'],
        );

        return response()->json([
            'message'      => 'Fecha de entrega actualizada correctamente.',
            'actualizados' => $actualizados,
        ]);
    }
}