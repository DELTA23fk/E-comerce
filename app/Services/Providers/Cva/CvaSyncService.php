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
 *  - Manejar la paginación internamente en los métodos de actualización
 *  - NO decide qué filtros HTTP usar (responsabilidad del Repository)
 *  - NO persiste nada en BD (responsabilidad del Orquestador)
 *
 * ─── DOS MÉTODOS DE PAGINACIÓN INTERNOS ──────────────────────────────────────
 *
 *  paginarSyncInicial($filtros)  → getProductsGeneral()          (datos completos)
 *  paginarActualizacion()        → getProductosParaActualizacion() (datos ligeros)
 *
 * El sync inicial usa el endpoint con imágenes, descripciones técnicas, etc.
 * Las actualizaciones usan el endpoint mínimo: solo precio + stock + promos.
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
    // SYNC INICIAL — paginación externa, filtros desde Command
    // =========================================================================

    /**
     * Una página del catálogo completo para sync inicial.
     * El orquestador controla el loop de páginas desde afuera.
     * Los filtros vienen del Command y el Repository los fusiona con los base.
     */
    public function obtenerPaginaDeProductos(array $filtros = [], int $pagina = 1): SyncPageResult
    {
        $respuesta = $this->api->obtenerProductosPara('general', $pagina);

        if ($respuesta->articulos->count() === 0) {
            return SyncPageResult::vacio($pagina);
        }

        $productos     = $this->transformarArticulos($respuesta->articulos, 'sync inicial');
        $totalPaginas  = $respuesta->paginacion->totalPaginas ?? 1;

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
     * Los filtros necesarios están encapsulados en el Repository.
     */
    public function obtenerProductoPorId(string $clave): ?ProductoData
    {
        $articulo = $this->api->getSingleProductByClave($clave);

        return $articulo ? ProductoFactory::fromCVA($articulo) : null;
    }

    // =========================================================================
    // CONSULTA UNIFICADA — paginación interna, sin filtros externos
    // =========================================================================

    /**
     * CVA devuelve precio + stock + promos en el mismo endpoint.
     * El orquestador llama a obtenerProductosParaActualizacion() UNA sola vez
     * y pasa la colección a los tres métodos de persistencia.
     */
    public function soportaConsultaUnificada(): bool
    {
        return true;
    }

    /**
     * Todos los productos con precio, stock y datos de promoción.
     * Usa el endpoint LIGERO de actualizaciones (sin imágenes ni dt).
     * Pagina internamente — el orquestador solo recibe la Collection final.
     */
    public function obtenerProductosParaActualizacion(): Collection
    {
        return $this->paginarActualizacion();
    }

    // =========================================================================
    // CONSULTAS SEPARADAS
    // CVA no tiene endpoints separados — todos reutilizan paginarActualizacion().
    // El orquestador solo llama a estos métodos cuando soportaConsultaUnificada()
    // = false, pero los dejamos implementados por consistencia con la interfaz.
    // =========================================================================

    public function obtenerProductosConPrecioActualizado(): Collection
    {
        return $this->paginarActualizacion();
    }

    public function obtenerProductosConStockActualizado(): Collection
    {
        return $this->paginarActualizacion();
    }

    public function obtenerProductosEnPromocion(): Collection
    {
        return $this->paginarActualizacion()
            ->filter(fn(ProductoData $dto) => $dto->esOferta)
            ->values();
    }

    // =========================================================================
    // HELPERS INTERNOS
    // =========================================================================

    /**
     * Paginación para ACTUALIZACIONES.
     * Usa getProductosParaActualizacion() → endpoint ligero (sin imágenes ni dt).
     * Sin filtros externos — el Repository encapsula los necesarios.
     */
    private function paginarActualizacion(): Collection
    {
        $todos        = collect();
        $pagina       = 1;
        $totalPaginas = null; // Se fija en la primera respuesta y no cambia

        do {
            $respuesta = $this->api->obtenerProductosPara('actualizacion', $pagina);

            if ($respuesta->articulos->count() === 0) break;

            // Fijar el total solo en la primera página
            if ($totalPaginas === null) {
                $totalPaginas = $respuesta->paginacion->totalPaginas ?? 1;
            }

            $todos = $todos->merge(
                $this->transformarArticulos($respuesta->articulos, "actualización pág. {$pagina}")
            );

            Log::info('[CVA] Página de actualización cargada', [
                'pagina'    => $pagina,
                'de'        => $totalPaginas,
                'productos' => $respuesta->articulos->count(),
                'acumulado' => $todos->count(),
            ]);

            $pagina++;

            // Pausa entre peticiones para no sobrecargar la API de CVA
            if ($pagina <= $totalPaginas) {
                sleep(1);
            }

        } while ($pagina <= $totalPaginas);

        return $todos;
    }

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