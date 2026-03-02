<?php

namespace App\Services\Traits;

use App\Models\AlmacenProductoStock;
use App\Models\Producto;
use App\Models\ProveedorProducto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

trait MapsProducto
{
    protected function mapPaginator(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $paginator->getCollection()->transform(fn($producto) => $this->mapProducto($producto));
        return $paginator;
    }

    protected function mapProducto(Producto $producto): array
    {
        // Campos base explícitos — nada de toArray() aquí para tener control total
        $data = [
            'id'                  => $producto->id,
            'nombre'              => $producto->nombre,
            'codigo_fabricante'   => $producto->codigo_fabricante,
            'codigo_barras'       => $producto->codigo_barras,
            'upc'                 => $producto->upc,
            'descripcion'         => $producto->descripcion,
            'descripcion_tecnica' => $producto->descripcion_tecnica,
            'marca_id'            => $producto->marca_id,
            'categoria_id'        => $producto->categoria_id,
            'familia_id'          => $producto->familia_id,
            'grupo_id'            => $producto->grupo_id,
            'sub_categoria_id'    => $producto->sub_categoria_id,
        ];

        // Mapear relaciones solo si fueron cargadas (eager o lazy)
        $this->mapRelacionesCargadas($producto, $data);

        return $data;
    }

    protected function mapRelacionesCargadas(Producto $producto, array &$data): void
    {
        // belongsTo simples → misma estructura, solo limpiar timestamps
        foreach (['categoria', 'subCategoria', 'familia', 'grupo', 'marca'] as $relation) {
            if ($producto->relationLoaded($relation)) {
                $value = $producto->$relation;
                // snake_case para la key de salida (subCategoria → sub_categoria)
                $data[Str::snake($relation)] = $value
                    ? $this->mapRelacionSimple($value)
                    : null;
            }
        }

        if ($producto->relationLoaded('imagenes')) {
            $data['imagenes'] = $producto->imagenes
                ->map(fn($img) => $this->mapImagen($img))
                ->values()
                ->all();
        }

        if ($producto->relationLoaded('proveedorProductos')) {
            $data['proveedor_productos'] = $producto->proveedorProductos
                ->map(fn($pp) => $this->mapProveedorProducto($pp))
                ->values()
                ->all();
        }
    }

    // ─── Mappers de relaciones ────────────────────────────────────────────────

    protected function mapRelacionSimple(Model $model): array
    {
        $data = $model->toArray();
        unset($data['created_at'], $data['updated_at'], $data['deleted_at']);
        return $data;
    }

    protected function mapImagen(Model $imagen): array
    {
        return [
            'id'         => $imagen->id,
            'url_imagen' => $imagen->url_imagen,
            'producto_id' => $imagen->producto_id,
        ];
    }

    protected function mapProveedorProducto(ProveedorProducto $pp): array
    {
        $data = [
            'id'                  => $pp->id,
            'proveedor_id'        => $pp->proveedor_id,
            'codigo_proveedor'    => $pp->codigo_proveedor,
            'stock_total'         => $pp->stock_total,
            'moneda'              => $pp->moneda,
            'garantia'            => $pp->garantia,
            'en_oferta'           => $pp->en_oferta,
            'ultima_actualizacion'=> $pp->ultima_actualizacion,
            'proveedor_id'        => $pp->proveedor_id,
            'producto_id'         => $pp->producto_id,
        ];

        if ($pp->relationLoaded('proveedor') && $pp->proveedor) {
            $data['proveedor'] = $this->mapRelacionSimple($pp->proveedor);
        }

        if ($pp->relationLoaded('precio') && $pp->precio) {
            $data['precio'] = $this->mapPrecio($pp->precio);
        }

        if ($pp->relationLoaded('promociones')) {
            $data['promociones'] = $pp->promociones
                ->map(fn($promo) => $this->mapPromocion($promo))
                ->values()
                ->all();
        }
        
        // almacenes con stock por ubicación
        if ($pp->relationLoaded('almacenes')) {
            $data['almacenes'] = $pp->almacenes
                ->map(fn($almacen) => $this->mapAlmacenStock($almacen))
                ->values()
                ->all();
        }

        return $data;
    }

    protected function mapPrecio(Model $precio): array
    {
        return [
            'id'              => $precio->id,
            'precio_venta'   => $precio->precio_venta,
            'precio_anterior' => $precio->precio_anterior,
            'moneda_venta'   => $precio->moneda_venta,
            'ultima_actualizacion' => $precio->ultima_actualizacion,
            'proveedor_producto_id' => $precio->proveedor_producto_id,
            // agrega los campos que necesites exponer
        ];
    }

    protected function mapPromocion(Model $promocion): array
    {
        return [
            'id'               => $promocion->id,
            'es_oferta'        => $promocion->es_oferta,
            'moneda_descuento' => $promocion->moneda_descuento,
            'precio_con_descuento'  => $promocion->precio_moneda_original == 'MXN'
                ? $promocion->precio_con_descuento_mxn
                : $promocion->precio_con_descuento,
            'expiracion'       => $promocion->expiracion,
            'clave_promocion'  => $promocion->clave_promocion,
            'descripcion_promocion' => $promocion->descripcion_promocion,
            'fecha_inicio'     => $promocion->fecha_inicio,
            'expiracion_fecha' => $promocion->expiracion_fecha,
            'expiracion_texto' => $promocion->expiracion_texto,
            'cantidad_minima'  => $promocion->cantidad_minima,
            'disponible_en_promocion' => $promocion->disponible_en_promocion,
            'proveedor_producto_id' => $promocion->proveedor_producto_id,
            
        ];
    }
    protected function mapAlmacenStock(AlmacenProductoStock $almacen): array
    {
        $data = [
            'cantidad'             => $almacen->cantidad,
            'backorder'            => $almacen->backorder,
            'eta_backorder'        => $almacen->eta_backorder?->format('Y-m-d'),
            'tiene_stock'          => $almacen->tiene_stock,
            'tiene_backorder'      => $almacen->tiene_backorder,
            'ultima_actualizacion' => $almacen->ultima_actualizacion,
        ];

        if ($almacen->relationLoaded('almacen') && $almacen->almacen) {
            $data['almacen'] = [
                'id'           => $almacen->almacen->id,
                'nombre'       => $almacen->almacen->nombre,
                'codigo_postal'=> $almacen->almacen->codigo_postal,
                'es_principal' => $almacen->almacen->es_principal,
                'es_cd'        => $almacen->almacen->es_cd,
            ];
        }

        return $data;
    }
}