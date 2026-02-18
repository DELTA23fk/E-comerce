<?php

namespace App\Data\Pedidos;

readonly class CotizacionEnvioData
{
    public function __construct(
        public int $proveedorId,
        public float $subtotal,
        public float $iva,
        public float $montoTotal,
        public ?array $detalles = null, // Info adicional del proveedor
        public ?string $error = null
    ) {}

    public function tieneError(): bool
    {
        return $this->error !== null;
    }

    public function toArray(): array
    {
        return [
            'proveedor_id' => $this->proveedorId,
            'subtotal' => $this->subtotal,
            'iva' => $this->iva,
            'monto_total' => $this->montoTotal,
            'detalles' => $this->detalles,
            'error' => $this->error,
        ];
    }
}