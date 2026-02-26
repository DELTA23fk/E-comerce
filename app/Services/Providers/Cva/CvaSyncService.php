<?php

namespace App\Services\Providers\Cva;

use App\Contratos\ProveedorSyncInterface;
use App\Contratos\SyncPageResult;
use App\Data\Producto\ProductoData;
use App\Factories\ProductoFactory;
use App\Repository\CvaRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de sincronización para el proveedor CVA.
 *
 * Responsabilidades:
 *  - Llamar al CvaRepository con el método correcto según el caso de uso
 *  - Transformar ArticuloData → ProductoData vía ProductoFactory
 *  - Devolver UNA página por llamada — el orquestador controla el loop
 *  - NO decide qué filtros HTTP usar (responsabilidad del Repository)
 *  - NO persiste nada en BD (responsabilidad del Orquestador)
 *
 * ─── DOS ENDPOINTS ────────────────────────────────────────────────────────────
 *
 *  obtenerPaginaDeProductos()         → 'general'       (datos completos)
 *  obtenerProductosParaActualizacion() → 'actualizacion' (datos ligeros)
 *
 * El sync inicial usa el endpoint con imágenes, descripciones técnicas, etc.
 * Las actualizaciones usan el endpoint mínimo: solo precio + stock + promos.
 *
 * CVA no tiene endpoints separados por tipo — el mismo endpoint ligero
 * sirve para precio, stock y promociones simultáneamente.
 */
class CvaSyncService implements ProveedorSyncInterface
{
    public function __construct(
        private readonly CvaRepository $api,
    ) {}

    // =========================================================================
    // IDENTIFICACIÓN
    // =========================================================================

    public function getProveedorClave(): string
    {
        return 'cva';
    }

    // =========================================================================
    // SYNC INICIAL — paginación externa
    // =========================================================================

    /**
     * Una página del catálogo completo para sync inicial.
     * El orquestador controla el loop de páginas desde afuera.
     */
    public function obtenerPaginaDeProductos(array $filtros = [], int $pagina = 1): SyncPageResult
    {
        $respuesta = $this->api->obtenerProductosPara('general', $pagina);

        if ($respuesta->articulos->count() === 0) {
            return SyncPageResult::vacio($pagina);
        }

        $productos    = $this->transformarArticulos($respuesta->articulos, "sync inicial pág. {$pagina}");
        $totalPaginas = $respuesta->paginacion->totalPaginas ?? 1;

        return new SyncPageResult(
            productos: $productos,
            paginaActual: $pagina,
            totalPaginas: $totalPaginas,
            hayMasPaginas: $pagina < $totalPaginas,
            totalProductos: $respuesta->paginacion->totalArticulos ?? 0,
        );
    }

    /**
     * Producto individual por clave. Usado para webhooks y tiempo real.
     */
    public function obtenerProductoPorId(string $clave): ?ProductoData
    {
        $articulo = $this->api->getSingleProductByClave($clave);

        return $articulo ? ProductoFactory::fromCVA($articulo) : null;
    }

    // =========================================================================
    // ACTUALIZACIONES — CONSULTA UNIFICADA
    // =========================================================================

    /**
     * CVA devuelve precio + stock + promos en el mismo endpoint ligero.
     * El orquestador llama a obtenerProductosParaActualizacion() en loop
     * y pasa cada página a los tres métodos de persistencia.
     */
    public function soportaConsultaUnificada(): bool
    {
        return true;
    }

    /**
     * Una página de productos con precio, stock y datos de promoción.
     * Usa el endpoint LIGERO (sin imágenes ni descripción técnica).
     * El orquestador controla el loop — aquí solo se devuelve una página.
     */
    public function obtenerProductosParaActualizacion(int $pagina = 1): SyncPageResult
    {
        $respuesta = $this->api->obtenerProductosPara('actualizacion', $pagina);

        if ($respuesta->articulos->count() === 0) {
            return SyncPageResult::vacio($pagina);
        }

        $productos    = $this->transformarArticulos($respuesta->articulos, "actualización pág. {$pagina}");
        $totalPaginas = $respuesta->paginacion->totalPaginas ?? 1;

        Log::info('[CVA] Página de actualización obtenida', [
            'pagina'    => $pagina,
            'de'        => $totalPaginas,
            'productos' => $productos->count(),
        ]);

        return new SyncPageResult(
            productos: $productos,
            paginaActual: $pagina,
            totalPaginas: $totalPaginas,
            hayMasPaginas: $pagina < $totalPaginas,
            totalProductos: $respuesta->paginacion->totalArticulos ?? 0,
        );
    }

    // =========================================================================
    // ACTUALIZACIONES — CONSULTA SEPARADA (no aplica para CVA)
    // CVA usa un solo endpoint para precio + stock + promos.
    // Estos métodos no deben llamarse cuando soportaConsultaUnificada() = true.
    // =========================================================================

    /** @throws \BadMethodCallException */
    public function obtenerProductosConPrecioActualizado(int $pagina = 1): SyncPageResult
    {
        throw new \BadMethodCallException(
            static::class . ' usa consulta unificada. Llamar obtenerProductosParaActualizacion().'
        );
    }

    /** @throws \BadMethodCallException */
    public function obtenerProductosConStockActualizado(int $pagina = 1): SyncPageResult
    {
        throw new \BadMethodCallException(
            static::class . ' usa consulta unificada. Llamar obtenerProductosParaActualizacion().'
        );
    }

    /** @throws \BadMethodCallException */
    public function obtenerProductosEnPromocion(int $pagina = 1): SyncPageResult
    {
        throw new \BadMethodCallException(
            static::class . ' usa consulta unificada. Llamar obtenerProductosParaActualizacion().'
        );
    }

    // =========================================================================
    // HELPERS INTERNOS
    // =========================================================================

    /**
     * Transforma una colección de ArticuloData → ProductoData.
     * Omite y loguea los artículos que fallen la transformación.
     */
    private function transformarArticulos(iterable $articulos, string $contexto): Collection
    {
        $productos = collect();

        foreach ($articulos as $articulo) {
            try {
                $productos->push(ProductoFactory::fromCVA($articulo));
            } catch (\Throwable $e) {
                Log::warning("[CVA] Error transformando artículo en {$contexto}", [
                    'id'    => $articulo->id ?? 'desconocido',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $productos;
    }
}