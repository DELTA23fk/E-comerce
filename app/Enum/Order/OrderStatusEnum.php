<?php

namespace App\Enum\Order;

enum OrderStatusEnum: string
{
    case PENDIENTE_PAGO = 'pendiente_pago';
    case PENDIENTE = 'pendiente';
    case PROCESANDO = 'procesando';
    case PROCESADO = 'procesado';
    case ENVIADO = 'enviado';
    case ENTREGADO = 'entregado';
    case CANCELADO = 'cancelado';
    case FALLIDO = 'fallido';

    /**
     * Obtener todos los valores como array
     */
    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }

    /**
     * Obtener descripción amigable del estado
     */
    public function label(): string
    {
        return match($this) {
            self::PENDIENTE_PAGO => 'Pendiente de Pago',
            self::PENDIENTE => 'Pendiente',
            self::PROCESANDO => 'En Procesando',
            self::PROCESADO => 'Procesado',
            self::ENVIADO => 'Enviado',
            self::ENTREGADO => 'Entregado',
            self::CANCELADO => 'Cancelado',
            self::FALLIDO => 'Fallido',
        };
    }
}
