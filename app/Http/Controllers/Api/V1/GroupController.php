<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Grupo;
use App\Services\Groups\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupController extends Controller
{
   public function __construct(
        private readonly GroupService $grupoService
    ) {}

    // GET /api/grupos
    public function index(): JsonResponse
    {
        return response()->json([
            'data'    => $this->grupoService->getAll(),
            'message' => 'Grupos obtenidos correctamente.',
        ]);
    }

    // GET /api/grupos/{id}
    public function show(int $id): JsonResponse
    {
        $grupo = $this->grupoService->getById($id);

        if (! $grupo) {
            return response()->json(['message' => 'Grupo no encontrado.'], 404);
        }

        return response()->json(['data' => $grupo]);
    }

    // POST /api/grupos
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255|unique:grupos,nombre',
        ]);

        $grupo = $this->grupoService->create($validated);

        return response()->json([
            'data'    => $grupo,
            'message' => 'Grupo creado correctamente.',
        ], 201);
    }

    // PUT|PATCH /api/grupos/{grupo}
    public function update(Request $request, Grupo $grupo): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255|unique:grupos,nombre,' . $grupo->id,
        ]);

        $grupo = $this->grupoService->update($grupo, $validated);

        return response()->json([
            'data'    => $grupo,
            'message' => 'Grupo actualizado correctamente.',
        ]);
    }

    // DELETE /api/grupos/{grupo}
    public function destroy(Grupo $grupo): JsonResponse
    {
        $this->grupoService->delete($grupo);

        return response()->json(['message' => 'Grupo eliminado correctamente.']);
    }

    // POST /api/grupos/cache/refresh
    public function refreshCache(): JsonResponse
    {
        $this->grupoService->refreshCache();

        return response()->json(['message' => 'Caché de grupos refrescado correctamente.']);
    }
}
