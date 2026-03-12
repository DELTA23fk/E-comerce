<?php

namespace App\Contratos;

use App\Data\Pedidos\CotizacionEnvioData;
use App\Data\Pedidos\PedidoProveedorRequestData;
use App\Data\Pedidos\ProductoEnriquecidoData;
use App\Models\Cliente;

interface ProveedorServiceInterface
{
    /**
     * Enriquecer productos con precios, ofertas y metadata del proveedor.
     * El servicio es responsable de aplicar su lógica de negocio (promos, descuentos, etc.).
     *
     * @param  array $productosBasicos  [['codigo_proveedor' => 'XX', 'cantidad' => 2], ...]
     * @return ProductoEnriquecidoData[]
     */
    public function enriquecerProductos(array $productosBasicos): array;

    /**
     * Validar disponibilidad de stock según las reglas del proveedor.
     *
     * - Si $almacenPreferido se especifica, el proveedor puede tenerlo en cuenta
     *   para advertir (log) si ese almacén en particular no cubre la demanda,
     *   pero la excepción solo se lanza si el stock TOTAL es insuficiente.
     * - Lanza excepción específica del proveedor si no hay stock suficiente.
     *
     * @param  ProductoEnriquecidoData[] $productos
     * @param  string|int|null           $almacenPreferido  Clave externa o id interno. Opcional.
     * @throws \App\Exceptions\Cva\CvaStockException  (u equivalente del proveedor)
     */
    public function validarDisponibilidad(
        array           $productos,
        string|int|null $almacenPreferido = null,
    ): bool;

    /**
     * Construir ProductoEnriquecidoData básicos para cotización de envío rápida.
     *
     * NO aplica precios ni promociones. Solo los campos que el proveedor necesita
     * para calcular peso/volumen/flete. El orquestador llama este método cuando
     * el único objetivo es cotizar envío, sin crear pedido.
     *
     * @param  array $productosBasicos  [['codigo_proveedor' => 'XX', 'cantidad' => 2], ...]
     * @return ProductoEnriquecidoData[]
     */
    public function prepararParaCotizacion(array $productosBasicos): array;

    /**
     * Cotizar envío sin crear pedido.
     *
     * SOLO calcula costos de envío. No enriquece productos ni aplica descuentos.
     * Recibe ProductoEnriquecidoData[] porque ya vienen del paso de enriquecimiento
     * o, en cotizaciones rápidas, con datos básicos (precio = 0 es válido).
     *
     * @param  ProductoEnriquecidoData[] $productos
     * @param  Cliente                   $cliente
     * @param  string|int|null           $almacenPreferido  Opcional.
     * @throws \App\Exceptions\Orders\ShippingOutOfRangeException
     * @throws \App\Exceptions\Orders\ShippingQuoteException
     */
    public function cotizarEnvio(
        array           $productos,
        Cliente         $cliente,
        string|int|null $almacenPreferido = null,
    ): CotizacionEnvioData;

    /**
     * Crear pedido con el proveedor externo.
     *
     * El almacén preferido viaja dentro del $request (ver PedidoProveedorRequestData).
     * El orquestador lo persiste en Pedido::almacen_preferido durante la Fase 1
     * y lo recupera aquí en la Fase 2.
     *
     * Retorna un array estandarizado para que el orquestador pueda procesarlo
     * independientemente del proveedor:
     *
     * Éxito:
     * [
     *   'success' => true,
     *   'data'    => [          // array de sub-órdenes (1 o más por pedido multi-almacén)
     *     [
     *       'folioPedido'  => string,
     *       'subtotal'     => float,
     *       'iva'          => float,
     *       'total'        => float,
     *       'moneda'       => string,
     *       'emailAgente'  => string|null,
     *       'emailAlmacen' => string|null,
     *       'flete'        => ['subtotal' => float, 'iva' => float, 'monto_total' => float],
     *       'origen'       => string,   // nombre del almacén de despacho
     *     ],
     *     ...
     *   ],
     *   'metadata' => [...]   // datos adicionales del proveedor (opcional)
     * ]
     *
     * Fallo:
     * [
     *   'success' => false,
     *   'error'   => string,
     * ]
     *
     * @param  PedidoProveedorRequestData $request  Incluye almacenPreferido.
     * @param  Cliente                    $cliente
     */
    public function crearPedido(
        PedidoProveedorRequestData $request,
        Cliente                    $cliente,
    ): array;

    /**
     * Obtener estatus de un pedido ya creado.
     */
    public function obtenerEstatus(string $folioPedido): string;

    /**
     * Cancelar un pedido ya creado.
     */
    public function cancelarPedido(string $folioPedido): bool;

    /**
     * Nombre legible del proveedor (usado en logs y respuestas de cotización).
     */
    public function obtenerNombre(): string;

    /**
     * Código identificador del proveedor.
     */
    public function codigoProveedor(): string;
}