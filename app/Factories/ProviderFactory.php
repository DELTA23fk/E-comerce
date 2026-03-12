<?php

namespace App\Factories;

use App\Contratos\ProveedorServiceInterface;
use App\Services\Providers\Cva\CvaProviderOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ProviderFactory
{
    private ?array $mapa = null;

    public function __construct(
        private readonly CvaProviderOrderService    $cvaService,
    ) {}

    public function crear(int $proveedorId): ProveedorServiceInterface
    {
        return $this->mapa()[$proveedorId]
            ?? throw new InvalidArgumentException("No hay servicio para proveedor ID: {$proveedorId}");
    }

    private function mapa(): array
    {
        if ($this->mapa !== null) {
            return $this->mapa;
        }

        $this->mapa = [];

        foreach ($this->servicios() as $servicio) {
            $id = DB::table('proveedores')
                ->where('codigo_proveedor', $servicio->codigoProveedor())
                ->value('id');

            if ($id) {
                $this->mapa[$id] = $servicio;
            } else {
                Log::warning("[ProviderFactory] '{$servicio->codigoProveedor()}' no encontrado en BD.");
            }
        }

        return $this->mapa;
    }

    // Añadir proveedor nuevo = una línea aquí, nada más.
    private function servicios(): array
    {
        return [
            $this->cvaService,
        ];
    }
}
