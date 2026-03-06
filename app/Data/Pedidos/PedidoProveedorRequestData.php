<?php

namespace App\Data\Pedidos;

/**
 * Datos que el orquestador entrega a cada ProveedorService para crear un pedido.
 *
 * Responsabilidades:
 *  - Transportar SOLO los datos que el proveedor necesita para crear su orden externa.
 *  - No contiene lógica de negocio.
 *  - almacenPreferido viene persistido desde la Fase 1 (Pedido::almacen_preferido).
 *
 * No incluye proveedorId: para cuando se construye este objeto el orquestador
 * ya agrupó los productos por proveedor y está invocando al servicio correcto.
 * El proveedor se conoce a sí mismo — pasarlo aquí sería información redundante.
 */
readonly class PedidoProveedorRequestData
{
    /**
     * @param string            $numeroOrden       Folio del pedido maestro (e.g. NXTPED-20250304-1234).
     * @param array             $productos         [['proveedor_producto_id', 'codigo_proveedor', 'cantidad', 'precio_unitario'], ...]
     *                                             Todos pertenecen al mismo proveedor — el orquestador ya los agrupó.
     * @param array|null        $datosEnvio        Dirección de entrega (sobrescribe datos del cliente si se pasa).
     * @param bool              $test              Si true, la orden se crea en modo sandbox.
     * @param string|null       $observaciones     Notas adicionales para el proveedor.
     * @param bool              $cotiza_flete      Si el proveedor debe incluir cotización de flete en la respuesta.
     * @param string|int|null   $almacenPreferido  Clave externa ('1','46'...) o id interno.
     *                                             Null = el proveedor decide el almacén óptimo.
     */
    public function __construct(
        public string          $numeroOrden,
        public array           $productos,
        public ?array          $datosEnvio       = null,
        public bool            $test             = true,
        public ?string         $observaciones    = null,
        public bool            $cotiza_flete     = false,
        public string|int|null $almacenPreferido = null,
    ) {}
}