<?php

namespace App\Services\Collaborators;

use App\Models\Collaborator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CollaboratorsService
{

    public function getAllCollaborators():array
    {
        return Collaborator::all()->map(function ($collaborator) {
            return $this->formatCollaborator($collaborator);
        })->toArray();
    }

    public function getCollaboratorById($id)
    {
        $collaborator = Collaborator::findOrFail($id);
        return $this->formatCollaborator($collaborator);
    }

    public function createCollaborator($data)
    {
        if (isset($data['avatar'])) {
            $avatar = $this->saveStorageImage($data['avatar']);
            $data['avatar_url'] = $avatar['url'];
            $data['image_url'] = $avatar['path'];
            unset($data['avatar']);
        }

        return Collaborator::create($data);
    }

    public function updateCollaborator($id, $data)
    {
        $collaborator = Collaborator::findOrFail($id);

        if (isset($data['avatar'])) {
            if ($collaborator->image_url) {
                Storage::disk('public')->delete($collaborator->image_url);
            }
            $avatar = $this->saveStorageImage($data['avatar']);
            $data['avatar_url'] = $avatar['url'];
            $data['image_url'] = $avatar['path'];
            unset($data['avatar']);
        }

        $collaborator->update($data);
        return $collaborator;
    }

    public function deleteCollaborator($id): void
    {
        $collaborator = Collaborator::find($id);
        if (!$collaborator) {
            throw new NotFoundHttpException("Colaborador no encontrado");
        }

        if ($collaborator->image_url) {
            Storage::disk('public')->delete($collaborator->image_url);
        }

        $collaborator->delete();
    }

    private function saveStorageImage($image): array
    {
        $path = $image->store('collaborators', 'public');
        return [
            'path' => $path,
            'url'  => asset('storage/' . $path),
        ];
    }

    private function formatCollaborator(Collaborator $collaborator): array
    {
        return [
            'id'          => $collaborator->id,
            'name'        => $collaborator->name,
            'description' => $collaborator->description,
            'avatar_url'  => $collaborator->avatar_url,
            'position'    => $collaborator->position,
            'joined_at'   => $collaborator->joined_at?->format('Y-m-d'),
            'left_at'     => $collaborator->left_at?->format('Y-m-d'),
        ];
    }
}