<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Services\Categories\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categoriaService
    ) {}

    // ------------------------------------------------------------------
    // CRUD principal
    // ------------------------------------------------------------------

    // GET /api/categorias
    // Query param: ?with_subcategorias=true   → incluye la relación
    public function index(Request $request): JsonResponse
    {
        $categorias = $request->boolean('with_subcategorias')
            ? $this->categoriaService->getAllWithSubcategorias()
            : $this->categoriaService->getAll();

        return response()->json([
            'data'    => $categorias,
            'message' => 'Categorías obtenidas correctamente.',
        ]);
    }

    // GET /api/categorias/{id}   (siempre incluye subcategorías)
    public function show(int $id): JsonResponse
    {
        $categoria = $this->categoriaService->getById($id);

        if (! $categoria) {
            return response()->json(['message' => 'Categoría no encontrada.'], 404);
        }

        return response()->json(['data' => $categoria]);
    }

    // POST /api/categorias
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre'             => 'required|string|max:255|unique:categorias,nombre',
            'sub_categoria_ids'  => 'sometimes|array',
            'sub_categoria_ids.*'=> 'integer|exists:sub_categorias,id',
        ]);

        $categoria = $this->categoriaService->create($validated);

        return response()->json([
            'data'    => $categoria,
            'message' => 'Categoría creada correctamente.',
        ], 201);
    }

    // PUT|PATCH /api/categorias/{categoria}
    public function update(Request $request, Categoria $categoria): JsonResponse
    {
        $validated = $request->validate([
            'nombre'             => 'required|string|max:255|unique:categorias,nombre,' . $categoria->id,
            'sub_categoria_ids'  => 'sometimes|array',
            'sub_categoria_ids.*'=> 'integer|exists:sub_categorias,id',
        ]);

        $categoria = $this->categoriaService->update($categoria, $validated);

        return response()->json([
            'data'    => $categoria,
            'message' => 'Categoría actualizada correctamente.',
        ]);
    }

    // DELETE /api/categorias/{categoria}
    public function destroy(Categoria $categoria): JsonResponse
    {
        $this->categoriaService->delete($categoria);

        return response()->json(['message' => 'Categoría eliminada correctamente.']);
    }

    // ------------------------------------------------------------------
    // Gestión granular de subcategorías
    // ------------------------------------------------------------------

    // POST /api/categorias/{categoria}/subcategorias/attach
    // Body: { "sub_categoria_ids": [1, 2, 3] }
    // Agrega subcategorías sin quitar las existentes.
    public function attachSubcategorias(Request $request, Categoria $categoria): JsonResponse
    {
        $validated = $request->validate([
            'sub_categoria_ids'   => 'required|array|min:1',
            'sub_categoria_ids.*' => 'integer|exists:sub_categorias,id',
        ]);

        $categoria = $this->categoriaService->attachSubcategorias(
            $categoria,
            $validated['sub_categoria_ids']
        );

        return response()->json([
            'data'    => $categoria,
            'message' => 'Subcategorías añadidas correctamente.',
        ]);
    }

    // DELETE /api/categorias/{categoria}/subcategorias/detach
    // Body: { "sub_categoria_ids": [1, 2] }
    // Quita subcategorías específicas.
    public function detachSubcategorias(Request $request, Categoria $categoria): JsonResponse
    {
        $validated = $request->validate([
            'sub_categoria_ids'   => 'required|array|min:1',
            'sub_categoria_ids.*' => 'integer|exists:sub_categorias,id',
        ]);

        $categoria = $this->categoriaService->detachSubcategorias(
            $categoria,
            $validated['sub_categoria_ids']
        );

        return response()->json([
            'data'    => $categoria,
            'message' => 'Subcategorías removidas correctamente.',
        ]);
    }

    // PUT /api/categorias/{categoria}/subcategorias/sync
    // Body: { "sub_categoria_ids": [1, 4, 7] }
    // Reemplaza TODAS las subcategorías con el conjunto enviado.
    public function syncSubcategorias(Request $request, Categoria $categoria): JsonResponse
    {
        $validated = $request->validate([
            'sub_categoria_ids'   => 'required|array',
            'sub_categoria_ids.*' => 'integer|exists:sub_categorias,id',
        ]);

        $categoria = $this->categoriaService->syncSubcategorias(
            $categoria,
            $validated['sub_categoria_ids']
        );

        return response()->json([
            'data'    => $categoria,
            'message' => 'Subcategorías sincronizadas correctamente.',
        ]);
    }

    // ------------------------------------------------------------------
    // Caché
    // ------------------------------------------------------------------

    // POST /api/categorias/cache/refresh
    public function refreshCache(): JsonResponse
    {
        $this->categoriaService->refreshCache();

        return response()->json(['message' => 'Caché refrescado correctamente.']);
    }
}
