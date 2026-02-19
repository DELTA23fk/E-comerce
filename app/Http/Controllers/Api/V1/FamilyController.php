<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Familia;
use App\Services\Families\FamilyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyController extends Controller
{
    public function __construct(
        private readonly FamilyService $familiaService
    ) {}

    // GET /api/familias
    public function index(): JsonResponse
    {
        return response()->json([
            'data'    => $this->familiaService->getAll(),
            'message' => 'Familias obtenidas correctamente.',
        ]);
    }

    // GET /api/familias/{id}
    public function show(int $id): JsonResponse
    {
        $familia = $this->familiaService->getById($id);

        if (! $familia) {
            return response()->json(['message' => 'Familia no encontrada.'], 404);
        }

        return response()->json(['data' => $familia]);
    }

    // POST /api/familias
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255|unique:familias,nombre',
        ]);

        $familia = $this->familiaService->create($validated);

        return response()->json([
            'data'    => $familia,
            'message' => 'Familia creada correctamente.',
        ], 201);
    }

    // PUT|PATCH /api/familias/{familia}
    public function update(Request $request, Familia $familia): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255|unique:familias,nombre,' . $familia->id,
        ]);

        $familia = $this->familiaService->update($familia, $validated);

        return response()->json([
            'data'    => $familia,
            'message' => 'Familia actualizada correctamente.',
        ]);
    }

    // DELETE /api/familias/{familia}
    public function destroy(Familia $familia): JsonResponse
    {
        $this->familiaService->delete($familia);

        return response()->json(['message' => 'Familia eliminada correctamente.']);
    }

    // POST /api/familias/cache/refresh
    public function refreshCache(): JsonResponse
    {
        $this->familiaService->refreshCache();

        return response()->json(['message' => 'Caché de familias refrescado correctamente.']);
    }
}
