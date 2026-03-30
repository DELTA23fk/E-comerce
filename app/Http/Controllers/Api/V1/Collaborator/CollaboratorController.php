<?php

namespace App\Http\Controllers\Api\V1\Collaborator;

use App\Http\Controllers\Controller;
use App\Services\Collaborators\CollaboratorsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class CollaboratorController extends Controller
{
    public function __construct(private readonly CollaboratorsService $collaboratorsService)
    {}

    public function index()
    {
        $data = $this->collaboratorsService->getAllCollaborators();
        return response()->json($data,200);
    }

    public function show($id)
    {
        $data = $this->collaboratorsService->getCollaboratorById($id);
        return response()->json($data,$data ? 200 : 404);
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string',
                'description' => 'nullable|string',
                'avatar' => 'nullable|image',
                'position' => 'nullable|string',
                'joined_at' => 'nullable|date',
                'left_at' => 'nullable|date|after_or_equal:joined_at'
            ]);

            $collaborator = $this->collaboratorsService->createCollaborator($data);
            return response()->json($collaborator,201);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al crear el colaborador', 'message' => $e->getMessage()], 400);
        }
        
    }

    public function update(Request $request, $id)
    {
        try {
            $data = $request->validate([
                'name' => 'sometimes|required|string',
                'description' => 'nullable|string',
                'avatar' => 'nullable|image',
                'position' => 'nullable|string',
                'joined_at' => 'nullable|date',
                'left_at' => 'nullable|date|after_or_equal:joined_at'
            ]);

            $collaborator = $this->collaboratorsService->updateCollaborator($id, $data);
            return response()->json($collaborator,200);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar el colaborador', 'message' => $e->getMessage()], 500);
        }
        
    }

    public function destroy($id)
    {
        try {
            $this->collaboratorsService->deleteCollaborator($id);
            return response()->json(['message' => 'Colaborador eliminado exitosamente'],204);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al eliminar el colaborador', 'message' => $e->getMessage()], 400);
        }
    }




}
