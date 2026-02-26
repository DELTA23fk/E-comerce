<?php

namespace App\Contratos;

use App\Data\Producto\ProductoData;
use Illuminate\Support\Collection;

/**
 * Contrato que todo servicio de sincronización de proveedor debe implementar.
 *
 * Cada proveedor (CVA, Exel, Syscom, CT, etc.) tendrá su propio servicio
 * que implemente esta interfaz, encapsulando:
 *   - La comunicación con la API del proveedor
 *   - La transformación de datos al DTO estándar ProductoData
 *
 * La persistencia en base de datos es responsabilidad del orquestador
 * a través de ProductoPersistenceService, no del proveedor.
 *
 * ─── CONSULTA UNIFICADA vs SEPARADA ─────────────────────────────────────────
 *
 * Algunos proveedores (ej. CVA) exponen un solo endpoint que devuelve precio,
 * stock y datos de promoción en la misma respuesta. Llamar a los tres métodos
 * individuales haría 3x el mismo recorrido de páginas sin necesidad.
 *
 * Para estos proveedores:
 *   1. soportaConsultaUnificada() → true
 *   2. obtenerProductosParaActualizacion() → Collection con precio + stock + promos
 *   3. El orquestador llama solo a este método y pasa la colección a los tres
 *      métodos de persistencia. 0 llamadas HTTP extra.
 *
 * Para proveedores con endpoints separados (Exel, Syscom, etc.):
 *   1. soportaConsultaUnificada() → false
 *   2. El orquestador llama a los tres métodos individuales normalmente.
 *   3. obtenerProductosParaActualizacion() no se invoca nunca.
 */
interface ProveedorSyncInterface
{
    // =========================================================================
    // IDENTIFICACIÓN
    // =========================================================================

    /**
     * Identificador único del proveedor.
     * Debe coincidir con la clave registrada en la tabla `proveedores`.
     *
     * Ejemplos: 'cva', 'exel', 'syscom', 'ct'
     */
    public function getProveedorClave(): string;

    // =========================================================================
    // SYNC INICIAL (paginación visible al orquestador)
    // =========================================================================

    /**
     * Obtiene una página de productos del proveedor y los convierte
     * al DTO estándar ProductoData.
     *
     * La paginación es controlada externamente por el Job/Command.
     *
     * @param  array  $filtros  Filtros específicos del proveedor (sku, marca, etc.)
     * @param  int    $pagina   Número de página (base 1)
     * @return SyncPageResult   Productos normalizados + metadatos de paginación
     */
    public function obtenerPaginaDeProductos(array $filtros, int $pagina): SyncPageResult;

    /**
     * Obtiene un único producto por su identificador en el proveedor.
     *
     * @param  string $idExterno  Clave/SKU del producto en el proveedor
     * @return ProductoData|null
     */
    public function obtenerProductoPorId(string $idExterno): ?ProductoData;

    // =========================================================================
    // ACTUALIZACIONES — CONSULTA UNIFICADA
    // =========================================================================

    /**
     * Indica si el proveedor devuelve precio + stock + promociones en una
     * sola llamada HTTP (o un solo recorrido de páginas).
     *
     * true  → el orquestador usará obtenerProductosParaActualizacion()
     *          y pasará la misma colección a los tres métodos de persistencia.
     * false → el orquestador llamará a los tres métodos individuales.
     */
    public function soportaConsultaUnificada(): bool;

    /**
     * [SOLO si soportaConsultaUnificada() === true]
     *
     * Obtiene todos los productos con precio, stock y datos de promoción
     * en una sola llamada/recorrido. El orquestador pasa esta colección a:
     *   - ProductoPersistenceService::actualizarPrecios()
     *   - ProductoPersistenceService::actualizarStock()
     *   - ProductoPersistenceService::actualizarPromociones()
     *
     * La paginación, si aplica, es INTERNA a este método.
     *
     * @return Collection<ProductoData>
     */
    public function obtenerProductosParaActualizacion(): Collection;

    // =========================================================================
    // ACTUALIZACIONES — CONSULTA SEPARADA
    // =========================================================================

    /**
     * [SOLO si soportaConsultaUnificada() === false]
     *
     * Obtiene todos los productos con precio actualizado.
     * La paginación, si aplica, es INTERNA a este método.
     *
     * @return Collection<ProductoData>
     */
    public function obtenerProductosConPrecioActualizado(): Collection;

    /**
     * [SOLO si soportaConsultaUnificada() === false]
     *
     * Obtiene todos los productos con stock actualizado.
     * La paginación, si aplica, es INTERNA a este método.
     *
     * @return Collection<ProductoData>
     */
    public function obtenerProductosConStockActualizado(): Collection;

    /**
     * [SOLO si soportaConsultaUnificada() === false]
     *
     * Obtiene todos los productos que tienen promociones activas.
     * La paginación, si aplica, es INTERNA a este método.
     *
     * @return Collection<ProductoData>
     */
    public function obtenerProductosEnPromocion(): Collection;
}