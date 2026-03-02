<?php

namespace App\Services;

use Illuminate\Http\Request;

class ProductRelationService
{
    /**
     * Construir array de relaciones basado en parámetros del request
     * 
     * @param Request $request
     * @return array
     */
    public function buildRelations(Request $request): array
    {
        $relations = [];

        // Relaciones simples (belongsTo)
        $relations = array_merge($relations, $this->getSimpleRelations($request));

        // Relación de imágenes
        if ($request->boolean('include_imagenes')) {
            $relations[] = 'imagenes';
        }

        // Relaciones de proveedor con nested relations
        $proveedorRelations = $this->getProveedorRelations($request);
        $relations = array_merge($relations, $proveedorRelations);

        return $relations;
    }

    /**
     * Obtener relaciones simples (belongsTo)
     * 
     * @param Request $request
     * @return array
     */
    private function getSimpleRelations(Request $request): array
    {
        $relations = [];

        $simpleRelationsMap = [
            'include_categoria' => 'categoria',
            'include_sub_categoria' => 'subCategoria',
            'include_familia' => 'familia',
            'include_grupo' => 'grupo',
            'include_marca' => 'marca',
        ];

        foreach ($simpleRelationsMap as $param => $relation) {
            if ($request->boolean($param)) {
                $relations[] = $relation;
            }
        }

        return $relations;
    }

    /**
     * Obtener relaciones de proveedor con nested relations
     * 
     * @param Request $request
     * @return array
     */
    private function getProveedorRelations(Request $request): array
    {
        $relations = [];

        if (!$request->boolean('include_proveedores')) {
            return $relations;
        }

        $nestedRelations = [];

        // Sub-relaciones de proveedor
        $nestedRelationsMap = [
            'include_proveedor_detalle' => 'proveedor',
            'include_precios' => 'precio',
            'include_promociones' => 'promociones',
        ];

        foreach ($nestedRelationsMap as $param => $relation) {
            if ($request->boolean($param)) {
                $nestedRelations[] = $relation;
            }
        }

        $loadProveedor = in_array('proveedor', $nestedRelations);
        if (!$loadProveedor) {
            $nestedRelations[] = 'proveedor';
        }

        return [
            'proveedorProductos' => function ($q) use ($nestedRelations, $loadProveedor) {
                // Solo proveedores activos
                $q->whereHas('proveedor', fn($q) => $q->where('activo', true));

                if (!empty($nestedRelations)) {
                    $q->with($nestedRelations);
                }
            },
        ];
    }

    /**
     * Verificar si se solicitó alguna relación
     * 
     * @param Request $request
     * @return bool
     */
    public function hasRelations(Request $request): bool
    {
        $relationParams = [
            'include_categoria',
            'include_sub_categoria',
            'include_familia',
            'include_grupo',
            'include_marca',
            'include_imagenes',
            'include_proveedores',
        ];

        foreach ($relationParams as $param) {
            if ($request->boolean($param)) {
                return true;
            }
        }

        return false;
    }
}
