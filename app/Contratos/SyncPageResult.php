<?php

namespace App\Contratos;

use Illuminate\Support\Collection;

/**
 * Objeto de valor que encapsula el resultado de una página de sincronización.
 *
 * Desacopla la representación de paginación específica de cada API
 * (CVA devuelve 'totalPaginas', otros pueden usar 'hasMore', cursores, etc.)
 * hacia un formato estándar que entiende el orquestador.
 */
readonly class SyncPageResult
{
    /**
     * @param  Collection  $productos       Colección de ProductoData normalizados
     * @param  int         $paginaActual    Página actual procesada
     * @param  int         $totalPaginas    Total de páginas disponibles (0 si desconocido)
     * @param  bool        $hayMasPaginas   Si hay más páginas por procesar
     * @param  int         $totalProductos  Total de productos (0 si el proveedor no lo reporta)
     */
    public function __construct(
        public readonly Collection $productos,
        public readonly int        $paginaActual,
        public readonly int        $totalPaginas,
        public readonly bool       $hayMasPaginas,
        public readonly int        $totalProductos = 0,
    ) {}

    /**
     * Crea un resultado vacío (fin de paginación o sin datos).
     */
    public static function vacio(int $pagina = 1): self
    {
        return new self(
            productos: collect(),
            paginaActual: $pagina,
            totalPaginas: 0,
            hayMasPaginas: false,
            totalProductos: 0,
        );
    }

    /**
     * Crea un resultado de una sola página (proveedor sin paginación).
     */
    public static function unica(Collection $productos): self
    {
        return new self(
            productos: $productos,
            paginaActual: 1,
            totalPaginas: 1,
            hayMasPaginas: false,
            totalProductos: $productos->count(),
        );
    }

    public function estaVacio(): bool
    {
        return $this->productos->isEmpty();
    }
}