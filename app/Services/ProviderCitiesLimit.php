<?php

namespace App\Services;

use App\Models\Proveedor;
use App\Models\ProveedorEstado;
use App\Models\ProveedorEstadoCiudad;
use App\Repository\CvaRepository;
use Illuminate\Support\Facades\DB;

class ProviderCitiesLimit
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private readonly CvaRepository $repositoryCva
    )
    {
        //
    }

    public function syncEstadosYCiudadesCVA()
    {
        $proveedorId = Proveedor::where('codigo_proveedor','cva')->value('id');
        $data = $this->repositoryCva->getCatalogoEstados();

        return DB::transaction(function () use ($data, $proveedorId) {
            foreach ($data['estados'] as $item) {
                $estadoData = $item['estado'];

                // 1. Guardar o actualizar Estado
                $estado = ProveedorEstado::updateOrCreate(
                    ['clave' => $estadoData['clave'], 'proveedor_id' => $proveedorId],
                    ['descripcion' => $estadoData['descripcion']]
                );

                // 2. Guardar o actualizar Ciudades
                foreach ($estadoData['ciudades'] as $ciudadData) {
                    ProveedorEstadoCiudad::updateOrCreate(
                        ['clave' => $ciudadData['clave'], 'proveedor_estado_id' => $estado->id],
                        ['descripcion' => $ciudadData['descripcion']]
                    );
                }
            }
            return count($data['estados']);
        });
    }
}
