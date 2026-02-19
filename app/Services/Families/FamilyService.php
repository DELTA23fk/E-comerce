<?php

namespace App\Services\Families;

use App\Models\Familia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FamilyService
{
    private const CACHE_KEY = 'familias:all';
    private const CACHE_TTL = 3600; // 1 hora

    // ---------------------------------------------------------------
    // Lectura
    // ---------------------------------------------------------------

    public function getAll(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Familia::all();
        });
    }

    public function getById(int $id): ?Familia
    {
        return $this->getAll()->firstWhere('id', $id);
    }

    // ---------------------------------------------------------------
    // Escritura
    // ---------------------------------------------------------------

    public function create(array $data): Familia
    {
        $familia = Familia::create($data);

        $this->refreshCache();

        return $familia;
    }

    public function update(Familia $familia, array $data): Familia
    {
        $familia->update($data);

        $this->refreshCache();

        return $familia->fresh();
    }

    public function delete(Familia $familia): bool
    {
        $deleted = $familia->delete();

        $this->refreshCache();

        return $deleted;
    }

    // ---------------------------------------------------------------
    // Gestión del caché
    // ---------------------------------------------------------------

    public function refreshCache(): void
    {
        Cache::forget(self::CACHE_KEY);

        Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => Familia::all());
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
