<?php

namespace App\Contratos;

use App\Data\Producto\ProductoData;

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
 * ─── CONSULTA UNIFICADA vs SEPARADA ──────────────────────────────────────────
 *
 * Algunos proveedores (ej. CVA) exponen un solo endpoint que devuelve precio,
 * stock y datos de promoción en la misma respuesta. Llamar a los tres métodos
 * individuales haría 3x el mismo recorrido de páginas sin necesidad.
 *
 * Para estos proveedores:
 *   1. soportaConsultaUnificada() → true
 *   2. obtenerProductosParaActualizacion(pagina) → SyncPageResult con precio + stock + promos
 *   3. El orquestador llama a este método en loop y pasa cada página a los tres
 *      métodos de persistencia. 1 HTTP por ciclo de página.
 *
 * Para proveedores con endpoints separados (Exel, Syscom, etc.):
 *   1. soportaConsultaUnificada() → false
 *   2. El orquestador llama a los tres métodos individuales en loop independiente.
 *   3. obtenerProductosParaActualizacion() no se invoca nunca.
 *
 * ─── PAGINACIÓN EXTERNA ───────────────────────────────────────────────────────
 *
 * Todos los métodos de actualización reciben $pagina y devuelven SyncPageResult.
 * El orquestador controla el loop — el proveedor solo devuelve una página.
 * Esto acota la memoria a ~500 productos en RAM independientemente del catálogo.
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
    // SYNC INICIAL — paginación externa
    // =========================================================================

    /**
     * Obtiene una página del catálogo completo (imágenes, descripción técnica
     * y todos los campos) convertida al DTO estándar ProductoData.
     *
     * La paginación es controlada externamente por el Job/Command/Orquestador.
     *
     * @param  array  $filtros  Filtros específicos del proveedor (sku, marca, etc.)
     * @param  int    $pagina   Número de página (base 1)
     * @return SyncPageResult   Productos normalizados + metadatos de paginación
     */
    public function obtenerPaginaDeProductos(array $filtros, int $pagina): SyncPageResult;

    /**
     * Obtiene un único producto por su identificador en el proveedor.
     * Usado para webhooks y sincronización en tiempo real.
     *
     * @param  string  $idExterno  Clave/SKU del producto en el proveedor
     * @return ProductoData|null
     */
    public function obtenerProductoPorId(string $idExterno): ?ProductoData;

    // =========================================================================
    // CAPACIDADES DEL PROVEEDOR
    // =========================================================================

    /**
     * Indica si el proveedor devuelve precio + stock + promociones en un
     * único endpoint (true) o en endpoints separados por tipo (false).
     *
     * true  → el orquestador usa solo obtenerProductosParaActualizacion()
     * false → el orquestador usa los tres métodos individuales
     */
    public function soportaConsultaUnificada(): bool;

    // =========================================================================
    // ACTUALIZACIONES — CONSULTA UNIFICADA (soportaConsultaUnificada = true)
    // =========================================================================

    /**
     * [SOLO si soportaConsultaUnificada() === true]
     *
     * Obtiene una página de productos con precio, stock y datos de promoción
     * en una sola llamada al endpoint ligero del proveedor.
     *
     * El orquestador controla el loop de páginas y pasa cada Collection a:
     *   - ProductoPersistenceService::actualizarPrecios()
     *   - ProductoPersistenceService::actualizarStock()
     *   - ProductoPersistenceService::actualizarPromociones()
     *
     * @param  int  $pagina  Número de página (base 1)
     * @return SyncPageResult
     */
    public function obtenerProductosParaActualizacion(int $pagina): SyncPageResult;

    // =========================================================================
    // ACTUALIZACIONES — CONSULTA SEPARADA (soportaConsultaUnificada = false)
    // =========================================================================

    /**
     * [SOLO si soportaConsultaUnificada() === false]
     *
     * Obtiene una página de productos con precio actualizado.
     *
     * @param  int  $pagina  Número de página (base 1)
     * @return SyncPageResult
     */
    public function obtenerProductosConPrecioActualizado(int $pagina): SyncPageResult;

    /**
     * [SOLO si soportaConsultaUnificada() === false]
     *
     * Obtiene una página de productos con stock actualizado.
     *
     * @param  int  $pagina  Número de página (base 1)
     * @return SyncPageResult
     */
    public function obtenerProductosConStockActualizado(int $pagina): SyncPageResult;

    /**
     * [SOLO si soportaConsultaUnificada() === false]
     *
     * Obtiene una página de productos con promociones activas.
     *
     * @param  int  $pagina  Número de página (base 1)
     * @return SyncPageResult
     */
    public function obtenerProductosEnPromocion(int $pagina): SyncPageResult;
}