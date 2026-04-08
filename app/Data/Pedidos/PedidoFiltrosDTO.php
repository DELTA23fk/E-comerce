<?php

namespace App\Data\Pedidos;

use App\Enum\Order\OrderStatusEnum;

class PedidoFiltrosDTO
{
    public function __construct(
        public readonly ?OrderStatusEnum $estatus       = null,
        public readonly ?string          $folio         = null,
        public readonly ?string          $fechaDesde    = null,
        public readonly ?string          $fechaHasta    = null,
        public readonly ?string          $paymentStatus = null,
        public readonly int              $perPage       = 15,
        public readonly string           $orderBy       = 'fecha_pedido',
        public readonly string           $orderDir      = 'desc',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            estatus:       isset($data['estatus'])
                               ? OrderStatusEnum::from($data['estatus'])
                               : null,
            folio:         $data['folio']          ?? null,
            fechaDesde:    $data['fecha_desde']    ?? null,
            fechaHasta:    $data['fecha_hasta']    ?? null,
            paymentStatus: $data['payment_status'] ?? null,
            perPage:       (int) ($data['per_page'] ?? 15),
            orderBy:       $data['order_by']       ?? 'fecha_pedido',
            orderDir:      $data['order_dir']      ?? 'desc',
        );
    }
}