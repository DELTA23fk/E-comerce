<?php

namespace App\Data\Pedidos;

readonly class ProductoPedidoData
{
     public function __construct(
        public string $clave,
        public int $cantidad,
    ) {
        if (empty($this->clave)) {
            throw new \InvalidArgumentException('La clave del producto es requerida');
        }

        if ($this->cantidad <= 0) {
            throw new \InvalidArgumentException('La cantidad debe ser mayor a 0');
        }
    }

    /**
     * Crear desde array del request
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['clave']) || !isset($data['cantidad'])) {
            throw new \InvalidArgumentException('Se requieren los campos: clave, cantidad');
        }

        return new self(
            clave: $data['clave'],
            cantidad: (int) $data['cantidad']
        );
    }

    /**
     * Convertir a array
     */
    public function toArray(): array
    {
        return [
            'clave' => $this->clave,
            'cantidad' => $this->cantidad
        ];
    }
}
