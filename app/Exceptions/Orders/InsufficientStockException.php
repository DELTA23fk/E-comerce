<?php

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Se lanza cuando un proveedor no tiene stock suficiente para cubrir
 * la cantidad solicitada de uno o más productos.
 *
 * Usado en dos momentos:
 *  - CHECK 1: dentro de OrderCreationService::crearPedido()
 *  - CHECK 2: dentro de SuborderRegistrationService::revalidarStock()
 */
class InsufficientStockException extends RuntimeException
{
    /**
     * @param  string     $codigoProveedor   Clave del producto sin stock.
     * @param  int        $cantidadSolicitada
     * @param  int        $cantidadDisponible 0 si no hay nada disponible.
     * @param  int|null   $proveedorId
     */
    public function __construct(
        private readonly string $codigoProveedor,
        private readonly int    $cantidadSolicitada,
        private readonly int    $cantidadDisponible = 0,
        private readonly ?int   $proveedorId        = null,
    ) {
        parent::__construct(
            "Stock insuficiente para [{$codigoProveedor}]: "
            . "solicitado={$cantidadSolicitada}, disponible={$cantidadDisponible}."
        );
    }

    public function getCodigoProveedor(): string { return $this->codigoProveedor; }
    public function getCantidadSolicitada(): int  { return $this->cantidadSolicitada; }
    public function getCantidadDisponible(): int  { return $this->cantidadDisponible; }
    public function getProveedorId(): ?int        { return $this->proveedorId; }

    /** Contexto listo para incluir en un JsonResponse o en un Log. */
    public function toArray(): array
    {
        return [
            'codigo_proveedor'    => $this->codigoProveedor,
            'cantidad_solicitada' => $this->cantidadSolicitada,
            'cantidad_disponible' => $this->cantidadDisponible,
            'proveedor_id'        => $this->proveedorId,
        ];
    }
}