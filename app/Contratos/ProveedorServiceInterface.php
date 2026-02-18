<?php

namespace App\Contratos;

use App\Data\Pedidos\CotizacionEnvioData;
use App\Data\Pedidos\PedidoProveedorRequestData;
use App\Data\Pedidos\ProductoEnriquecidoData;
use App\Models\Cliente;

interface ProveedorServiceInterface
{
    /**
     * Enriquecer productos con precios, ofertas y metadata del proveedor
     * El servicio de proveedor es responsable de aplicar su lógica de negocio
     * 
     * @param array $productosBasicos [['codigo_proveedor' => 'XX', 'cantidad' => 2], ...]
     * @return ProductoEnriquecidoData[]
     */
    public function enriquecerProductos(array $productosBasicos): array;

    /**
     * Validar disponibilidad de productos según las reglas del proveedor
     * Debe lanzar excepciones específicas si no hay stock
     * 
     * @param ProductoEnriquecidoData[] $productos
     * @throws \App\Exceptions\Cva\CvaStockException
     * @return bool
     */
    public function validarDisponibilidad(array $productos): bool;

    /**
     * Cotizar envío sin crear pedido
     * 
     * @param ProductoEnriquecidoData[] $productos
     * @param Cliente $cliente
     * @throws \App\Exceptions\Orders\ShippingOutOfRangeException
     * @throws \App\Exceptions\Orders\ShippingQuoteException
     * @return CotizacionEnvioData
     */
    public function cotizarEnvio(array $productos, Cliente $cliente): CotizacionEnvioData;

    /**
     * Crear pedido con el proveedor
     * 
     * @param PedidoProveedorRequestData $request
     * @param Cliente $cliente
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function crearPedido(PedidoProveedorRequestData $request, Cliente $cliente): array;

    /**
     * Verificar si este servicio soporta un proveedor específico
     */
    public function soporta(int $proveedorId): bool;

    /**
     * Obtener estatus de un pedido
     */
    public function obtenerEstatus(string $folioPedido): string;

    /**
     * Cancelar un pedido
     */
    public function cancelarPedido(string $folioPedido): bool;

    public function obtenerNombre():string;
}