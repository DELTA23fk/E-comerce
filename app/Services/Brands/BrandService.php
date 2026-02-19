<?php

namespace App\Services\Brands;

use App\Models\Marca;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class BrandService
{
    private const CACHE_KEY = 'marcas:all';
    private const CACHE_TTL = 3600; // 1 hora

    // ---------------------------------------------------------------
    // Lectura
    // ---------------------------------------------------------------

    public function getAll(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Marca::all();
        });
    }

    public function getById(int $id): ?Marca
    {
        return $this->getAll()->firstWhere('id', $id);
    }

    // ---------------------------------------------------------------
    // Escritura
    // ---------------------------------------------------------------

    public function create(array $data): Marca
    {
        $marca = Marca::create($data);

        $this->refreshCache();

        return $marca;
    }

    public function update(Marca $marca, array $data): Marca
    {
        $marca->update($data);

        $this->refreshCache();

        return $marca->fresh();
    }

    public function delete(Marca $marca): bool
    {
        $deleted = $marca->delete();

        $this->refreshCache();

        return $deleted;
    }

    // ---------------------------------------------------------------
    // Gestión del caché
    // ---------------------------------------------------------------

    public function refreshCache(): void
    {
        Cache::forget(self::CACHE_KEY);

        Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => Marca::all());
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
