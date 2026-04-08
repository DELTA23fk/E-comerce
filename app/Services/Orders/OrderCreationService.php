<?php

namespace App\Services\Orders;

use App\Enum\Order\OrderStatusEnum;
use App\Enum\Order\PaymentStatusEnum;
use App\Exceptions\Orders\ClientProfileNotFoundException;
use App\Exceptions\Orders\InsufficientStockException;
use App\Exceptions\Orders\ProductNotFoundException;
use App\Exceptions\Orders\ShippingOutOfRangeException;
use App\Exceptions\Orders\ShippingQuoteException;
use App\Factories\ProviderFactory;
use App\Models\Cliente;
use App\Models\DetallePedido;
use App\Models\Pedido;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
 
/**
 * Responsabilidad única: crear el pedido maestro antes del pago.
 *
 * - Resuelve el cliente autenticado.
 * - Normaliza y agrupa productos por proveedor.
 * - Ejecuta CHECK 1 de stock y cotización de envío.
 * - Persiste Pedido + DetallePedido en estado 'pendiente_pago'.
 */
class OrderCreationService
{
    public function __construct(
        private readonly ProviderFactory $proveedorFactory,
    ) {}
 
    /**
     * @throws ClientProfileNotFoundException
     * @throws ProductNotFoundException
     * @throws InsufficientStockException
     * @throws ShippingOutOfRangeException
     * @throws ShippingQuoteException
     */
    public function crearPedido(array $datos, string|int|null $almacenPreferido = null): Pedido
    {
        return DB::transaction(function () use ($datos, $almacenPreferido) {
            $cliente = $this->resolverCliente();
 
            $productosNormalizados = $this->normalizarProductos($datos['productos'] ?? []);
            $productosPorProveedor = $this->agruparProductosPorProveedor($productosNormalizados);
 
            $todosLosProductosEnriquecidos = [];
            $totalProductos                = 0;
            $totalEnvio                    = 0;
 
            foreach ($productosPorProveedor as $proveedorId => $productosDelProveedor) {
                $servicio = $this->proveedorFactory->crear($proveedorId);
 
                $enriquecidos = $servicio->enriquecerProductos($productosDelProveedor);
 
                // ── CHECK 1 DE STOCK ─────────────────────────────────────────
                $servicio->validarDisponibilidad($enriquecidos, $almacenPreferido);
                // ─────────────────────────────────────────────────────────────
 
                $cotizacion = $servicio->cotizarEnvio($enriquecidos, $cliente, $almacenPreferido);
 
                $totalProductos += collect($enriquecidos)->sum(fn($p) => $p->getSubtotal());
                $totalEnvio     += $cotizacion->montoTotal;
 
                $todosLosProductosEnriquecidos = array_merge(
                    $todosLosProductosEnriquecidos,
                    $enriquecidos,
                );
            }
 
            $folio = $this->generarFolioUnico();

            //SE CREA EL PEDIDO INICIAL CON EL ESTATUS PENDIENTE DE PAGO, SE CALCULA EL TOTAL DE LOS PRODUCTOS Y EL ENVÍO, SE ASIGNA EL FOLIO GENERADO Y SE ASOCIA AL CLIENTE
            $pedido = Pedido::create([
                'folio'                  => $folio,
                'fecha_pedido'           => now(),
                'estatus'                => OrderStatusEnum::PENDIENTE_PAGO->value,
                'cliente_id'             => $cliente->id,
                'precio_total'           => $totalProductos + $totalEnvio,
                'precio_total_productos' => $totalProductos,
                'precio_total_envio'     => $totalEnvio,
                'almacen_preferido'      => $almacenPreferido,
                'observaciones'          => $datos['observaciones'] ?? null,
                'payment_status'         => PaymentStatusEnum::PENDING->value,
            ]);
 
            $this->guardarDetallesPendientes($pedido, $todosLosProductosEnriquecidos);
 
            Log::info('[OrderCreation] Pedido creado — CHECK 1 de stock superado', [
                'pedido_id' => $pedido->id,
                'folio'     => $pedido->folio,
                'total'     => $pedido->precio_total,
            ]);
 
            return $pedido->fresh(['detalles']);
        });
    }
 
    // =========================================================================
    // PRIVADOS
    // =========================================================================
 
    private function resolverCliente(): Cliente
    {
        $cliente = Auth::user()?->cliente;
 
        if (!$cliente) {
            throw new ClientProfileNotFoundException(Auth::id());
        }
 
        return $cliente;
    }
 
    private function normalizarProductos(array $productos): array
    {
        return collect($productos)->map(fn($p) => [
            'codigo_proveedor' => $p['clave'],
            'cantidad'         => (int) $p['cantidad'],
        ])->toArray();
    }
 
    /** @throws ProductNotFoundException */
    private function agruparProductosPorProveedor(array $productos): array
    {
        $codigos = collect($productos)->pluck('codigo_proveedor')->unique()->toArray();
 
        $mapping = DB::table('proveedor_productos')
            ->whereIn('codigo_proveedor', $codigos)
            ->select('codigo_proveedor', 'proveedor_id')
            ->get()
            ->keyBy('codigo_proveedor');
 
        $agrupados = [];
 
        foreach ($productos as $producto) {
            $row = $mapping->get($producto['codigo_proveedor']);
 
            if (!$row) {
                throw new ProductNotFoundException($producto['codigo_proveedor']);
            }
 
            $agrupados[$row->proveedor_id][] = $producto;
        }
 
        return $agrupados;
    }
 
    private function guardarDetallesPendientes(Pedido $pedido, array $productos): void
    {
        $filas = collect($productos)->map(fn($p) => [
            'pedido_id'             => $pedido->id,
            'pedido_proveedor_id'   => null,
            'proveedor_producto_id' => $p->proveedorProductoId,
            'clave_proveedor'       => $p->codigoProveedor,
            'cantidad'              => $p->cantidad,
            'precio_unitario'       => $p->precioUnitario,
            'subtotal'              => $p->getSubtotal(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
 
        DetallePedido::insert($filas->toArray());
    }
 
    // ejemplo: NXTITPED-20260326-F3A91D0E
    private function generarFolioUnico(): string
    {
        do {
            $folio  = sprintf('NXTITPED-%s-%s', now()->format('Ymd'), strtoupper(bin2hex(random_bytes(4))));
            $existe = DB::table('pedidos')->where('folio', $folio)->exists();
        } while ($existe);
 
        return $folio;
    }
}