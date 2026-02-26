<?php

namespace App\Factories;

use App\Contratos\ProveedorServiceInterface;
use App\Services\Providers\Cva\CvaProviderOrderService;
use InvalidArgumentException;

class ProviderFactory
{
   private array $servicios = [];

    public function __construct(
        CvaProviderOrderService $cvaService,
        // Inyectar más servicios aquí
    ) {
        $this->servicios = [
            $cvaService,
        ];
    }

    public function crear(int $proveedorId): ProveedorServiceInterface
    {
        foreach ($this->servicios as $servicio) {
            if ($servicio->soporta($proveedorId)) {
                return $servicio;
            }
        }

        throw new InvalidArgumentException("No hay servicio para proveedor ID: {$proveedorId}");
    }
}
