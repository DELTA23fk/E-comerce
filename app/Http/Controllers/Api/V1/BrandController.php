<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Marca;
use App\Services\Brands\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
     public function __construct(
        private readonly BrandService $marcaService
    ) {}

    // GET /api/marcas
    public function index(): JsonResponse
    {
        return response()->json([
            'data'    => $this->marcaService->getAll(),
            'message' => 'Marcas obtenidas correctamente.',
        ]);
    }

    // GET /api/marcas/{id}
    public function show(int $id): JsonResponse
    {
        $marca = $this->marcaService->getById($id);

        if (! $marca) {
            return response()->json(['message' => 'Marca no encontrada.'], 404);
        }

        return response()->json(['data' => $marca]);
    }

    // POST /api/marcas
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255|unique:marcas,nombre',
        ]);

        $marca = $this->marcaService->create($validated);

        return response()->json([
            'data'    => $marca,
            'message' => 'Marca creada correctamente.',
        ], 201);
    }

    // PUT|PATCH /api/marcas/{marca}
    public function update(Request $request, Marca $marca): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255|unique:marcas,nombre,' . $marca->id,
        ]);

        $marca = $this->marcaService->update($marca, $validated);

        return response()->json([
            'data'    => $marca,
            'message' => 'Marca actualizada correctamente.',
        ]);
    }

    // DELETE /api/marcas/{marca}
    public function destroy(Marca $marca): JsonResponse
    {
        $this->marcaService->delete($marca);

        return response()->json(['message' => 'Marca eliminada correctamente.']);
    }

    // POST /api/marcas/cache/refresh
    public function refreshCache(): JsonResponse
    {
        $this->marcaService->refreshCache();

        return response()->json(['message' => 'Caché de marcas refrescado correctamente.']);
    }
}
