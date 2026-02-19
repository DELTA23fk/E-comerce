<?php

namespace App\Services\Categories;

use App\Models\Categoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CategoryService
{
    private const CACHE_KEY          = 'categorias:all';
    private const CACHE_KEY_WITH_SUB = 'categorias:all:with_subcategorias';
    private const CACHE_TTL          = 3600; // 1 hora

    // ---------------------------------------------------------------
    // Lectura
    // ---------------------------------------------------------------

    /**
     * Todas las categorías sin subcategorías (útil para selects simples).
     */
    public function getAll(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Categoria::all();
        });
    }

    /**
     * Todas las categorías con sus subcategorías anidadas.
     * Ideal para menús de navegación o listados completos.
     */
    public function getAllWithSubcategorias(): Collection
    {
        return Cache::remember(self::CACHE_KEY_WITH_SUB, self::CACHE_TTL, function () {
            return Categoria::with('subCategorias')->get();
        });
    }

    /**
     * Una categoría por ID con sus subcategorías (busca en caché).
     */
    public function getById(int $id): ?Categoria
    {
        return $this->getAllWithSubcategorias()->firstWhere('id', $id);
    }

    // ---------------------------------------------------------------
    // Escritura
    // ---------------------------------------------------------------

    /**
     * Crea una categoría y opcionalmente sincroniza subcategorías.
     */
    public function create(array $data): Categoria
    {
        $categoria = Categoria::create($data);

        if (! empty($data['sub_categoria_ids'])) {
            $categoria->subCategorias()->sync($data['sub_categoria_ids']);
        }

        $this->refreshCache();

        return $categoria->load('subCategorias');
    }

    /**
     * Actualiza una categoría y opcionalmente sincroniza subcategorías.
     */
    public function update(Categoria $categoria, array $data): Categoria
    {
        $categoria->update($data);

        // sync reemplaza el conjunto completo de subcategorías relacionadas
        if (isset($data['sub_categoria_ids'])) {
            $categoria->subCategorias()->sync($data['sub_categoria_ids']);
        }

        $this->refreshCache();

        return $categoria->load('subCategorias');
    }

    /**
     * Soft-delete: desvincula la pivot antes de eliminar.
     */
    public function delete(Categoria $categoria): bool
    {
        $categoria->subCategorias()->detach();

        $deleted = $categoria->delete();

        $this->refreshCache();

        return $deleted;
    }

    // ---------------------------------------------------------------
    // Gestión granular de subcategorías
    // ---------------------------------------------------------------

    /**
     * Agrega subcategorías sin quitar las que ya existen.
     */
    public function attachSubcategorias(Categoria $categoria, array $subCategoriaIds): Categoria
    {
        $categoria->subCategorias()->syncWithoutDetaching($subCategoriaIds);

        $this->refreshCache();

        return $categoria->load('subCategorias');
    }

    /**
     * Elimina subcategorías específicas de la categoría.
     */
    public function detachSubcategorias(Categoria $categoria, array $subCategoriaIds): Categoria
    {
        $categoria->subCategorias()->detach($subCategoriaIds);

        $this->refreshCache();

        return $categoria->load('subCategorias');
    }

    /**
     * Reemplaza todas las subcategorías con el nuevo conjunto enviado.
     */
    public function syncSubcategorias(Categoria $categoria, array $subCategoriaIds): Categoria
    {
        $categoria->subCategorias()->sync($subCategoriaIds);

        $this->refreshCache();

        return $categoria->load('subCategorias');
    }

    // ---------------------------------------------------------------
    // Gestión del caché
    // ---------------------------------------------------------------

    /**
     * Invalida ambas claves y las regenera inmediatamente desde la BD.
     */
    public function refreshCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY_WITH_SUB);

        Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => Categoria::all());

        Cache::remember(self::CACHE_KEY_WITH_SUB, self::CACHE_TTL, function () {
            return Categoria::with('subCategorias')->get();
        });
    }

    /**
     * Solo elimina el caché (la próxima petición lo regenerará).
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY_WITH_SUB);
    }
}
