<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Proveedor;
use App\Services\Providers\ProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    public function __construct(
        private readonly ProviderService $proveedorService
    ) {}

    // GET /api/proveedores
    // Query param: ?solo_activos=true  → solo los activos
    public function index(Request $request): JsonResponse
    {
        $proveedores = $request->boolean('solo_activos')
            ? $this->proveedorService->getActivos()
            : $this->proveedorService->getAll();

        return response()->json([
            'data'    => $proveedores,
            'message' => 'Proveedores obtenidos correctamente.',
        ]);
    }

    // GET /api/proveedores/{id}
    public function show(int $id): JsonResponse
    {
        $proveedor = $this->proveedorService->getById($id);

        if (! $proveedor) {
            return response()->json(['message' => 'Proveedor no encontrado.'], 404);
        }

        return response()->json(['data' => $proveedor]);
    }

    // GET /api/proveedores/codigo/{codigo}
    public function showByCodigo(string $codigo): JsonResponse
    {
        $proveedor = $this->proveedorService->getByCodigo($codigo);

        if (! $proveedor) {
            return response()->json(['message' => 'Proveedor no encontrado.'], 404);
        }

        return response()->json(['data' => $proveedor]);
    }

    // POST /api/proveedores
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo_proveedor' => 'required|string|max:100|unique:proveedores,codigo_proveedor',
            'nombre'           => 'required|string|max:255',
            'activo'           => 'boolean',
        ]);

        // Activo por defecto si no se especifica
        $validated['activo'] = $validated['activo'] ?? true;

        $proveedor = $this->proveedorService->create($validated);

        return response()->json([
            'data'    => $proveedor,
            'message' => 'Proveedor creado correctamente.',
        ], 201);
    }

    // PUT|PATCH /api/proveedores/{proveedor}
    public function update(Request $request, Proveedor $proveedor): JsonResponse
    {
        $validated = $request->validate([
            'codigo_proveedor' => 'sometimes|string|max:100|unique:proveedores,codigo_proveedor,' . $proveedor->id,
            'nombre'           => 'sometimes|string|max:255',
            'activo'           => 'sometimes|boolean',
        ]);

        $proveedor = $this->proveedorService->update($proveedor, $validated);

        return response()->json([
            'data'    => $proveedor,
            'message' => 'Proveedor actualizado correctamente.',
        ]);
    }

    // PATCH /api/proveedores/{proveedor}/toggle-activo
    public function toggleActivo(Proveedor $proveedor): JsonResponse
    {
        $proveedor = $this->proveedorService->toggleActivo($proveedor);

        $estado = $proveedor->activo ? 'activado' : 'desactivado';

        return response()->json([
            'data'    => $proveedor,
            'message' => "Proveedor {$estado} correctamente.",
        ]);
    }

    // DELETE /api/proveedores/{proveedor}
    public function destroy(Proveedor $proveedor): JsonResponse
    {
        $this->proveedorService->delete($proveedor);

        return response()->json(['message' => 'Proveedor eliminado correctamente.']);
    }

    // POST /api/proveedores/cache/refresh
    public function refreshCache(): JsonResponse
    {
        $this->proveedorService->refreshCache();

        return response()->json(['message' => 'Caché de proveedores refrescado correctamente.']);
    }
}
