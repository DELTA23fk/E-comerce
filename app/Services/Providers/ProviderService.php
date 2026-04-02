<?php

namespace App\Services\Providers;

use App\Factories\ProductoFactory;
use App\Jobs\ActualizarPrecioVentaProveedorJob;
use App\Models\Proveedor;
use App\Models\ProveedorProducto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProviderService
{
    private const CACHE_KEY        = 'proveedores:all';
    private const CACHE_KEY_ACTIVE = 'proveedores:activos';
    private const CACHE_TTL        = 3600; // 1 hora

    // ---------------------------------------------------------------
    // Lectura
    // ---------------------------------------------------------------

    /**
     * Todos los proveedores (activos e inactivos).
     */
    public function getAll(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Proveedor::all();
        });
    }

    /**
     * Solo los proveedores con activo = true.
     * Caché separado para no cargar inactivos en listados del front.
     */
    public function getActivos(): Collection
    {
        return Cache::remember(self::CACHE_KEY_ACTIVE, self::CACHE_TTL, function () {
            return Proveedor::where('activo', true)->get();
        });
    }

    /**
     * Busca en el caché completo para evitar queries extra.
     */
    public function getById(int $id): ?Proveedor
    {
        return $this->getAll()->firstWhere('id', $id);
    }

    /**
     * Busca por código de proveedor.
     */
    public function getByCodigo(string $codigo): ?Proveedor
    {
        return $this->getAll()->firstWhere('codigo_proveedor', $codigo);
    }

    // ---------------------------------------------------------------
    // Escritura
    // ---------------------------------------------------------------

    public function create(array $data): Proveedor
    {
        $proveedor = Proveedor::create($data);

        $this->refreshCache();

        return $proveedor;
    }

    public function update(Proveedor $proveedor, array $data): Proveedor
    {
        $proveedor->update($data);

        $this->refreshCache();

        return $proveedor->fresh();
    }

    /**
     * Activa o desactiva un proveedor sin eliminarlo.
     */
    public function toggleActivo(Proveedor $proveedor): Proveedor
    {
        $proveedor->update(['activo' => ! $proveedor->activo]);

        $this->refreshCache();

        return $proveedor->fresh();
    }

    /**
     * Despacha un job que recalcula y actualiza el precio de venta de los
     * productos relacionados a un proveedor en segundo plano.
     */
    public function updatePrecioVentaDeProductosRelacionados(int|string $proveedorId, int $nuevoPorcentajeUtilidad): void
    {
        $proveedor = $this->getById((int) $proveedorId);
        if (! $proveedor) {
            throw new NotFoundHttpException("Proveedor con ID {$proveedorId} no encontrado.");
        }
        ActualizarPrecioVentaProveedorJob::dispatch($proveedorId, $nuevoPorcentajeUtilidad);
    }

    public function delete(Proveedor $proveedor): bool
    {
        $deleted = $proveedor->delete();

        $this->refreshCache();

        return $deleted;
    }

    // ---------------------------------------------------------------
    // Gestión del caché
    // ---------------------------------------------------------------

    /**
     * Invalida y regenera ambas claves de caché.
     */
    public function refreshCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY_ACTIVE);

        Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => Proveedor::all());

        Cache::remember(self::CACHE_KEY_ACTIVE, self::CACHE_TTL, function () {
            return Proveedor::where('activo', true)->get();
        });
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY_ACTIVE);
    }
}
