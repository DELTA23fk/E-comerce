<?php

namespace App\Services\Groups;

use App\Models\Grupo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class GroupService
{
    private const CACHE_KEY = 'grupos:all';
    private const CACHE_TTL = 3600; // 1 hora

    // ---------------------------------------------------------------
    // Lectura
    // ---------------------------------------------------------------

    public function getAll(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Grupo::all();
        });
    }

    public function getById(int $id): ?Grupo
    {
        return $this->getAll()->firstWhere('id', $id);
    }

    // ---------------------------------------------------------------
    // Escritura
    // ---------------------------------------------------------------

    public function create(array $data): Grupo
    {
        $grupo = Grupo::create($data);

        $this->refreshCache();

        return $grupo;
    }

    public function update(Grupo $grupo, array $data): Grupo
    {
        $grupo->update($data);

        $this->refreshCache();

        return $grupo->fresh();
    }

    public function delete(Grupo $grupo): bool
    {
        $deleted = $grupo->delete();

        $this->refreshCache();

        return $deleted;
    }

    // ---------------------------------------------------------------
    // Gestión del caché
    // ---------------------------------------------------------------

    public function refreshCache(): void
    {
        Cache::forget(self::CACHE_KEY);

        Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => Grupo::all());
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
