<?php

namespace App\Enum\Order;

enum ProveedorOrderStatusEnum: string
{
    case CREADO      = 'creado';
    case PROCESANDO  = 'procesando';
    case PROCESADO   = 'procesado';
    case ENVIADO     = 'enviado';
    case ENTREGADO   = 'entregado';
    case CANCELADO   = 'cancelado';
    case FALLIDO     = 'fallido';

    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }

    public function label(): string
    {
        return match($this) {
            self::CREADO     => 'Creado',
            self::PROCESANDO => 'En Proceso',
            self::PROCESADO  => 'Procesado',
            self::ENVIADO    => 'Enviado',
            self::ENTREGADO  => 'Entregado',
            self::CANCELADO  => 'Cancelado',
            self::FALLIDO    => 'Fallido',
        };
    }

    /** Estados desde los que se puede avanzar (transiciones válidas) */
    public function siguientesPermitidos(): array
    {
        return match($this) {
            self::CREADO     => [self::PROCESANDO, self::CANCELADO],
            self::PROCESANDO => [self::PROCESADO, self::FALLIDO, self::CANCELADO],
            self::PROCESADO  => [self::ENVIADO],
            self::ENVIADO    => [self::ENTREGADO],
            self::ENTREGADO  => [],
            self::CANCELADO  => [],
            self::FALLIDO    => [self::PROCESANDO, self::CANCELADO],
        };
    }

    public function puedeTransicionarA(self $nuevo): bool
    {
        return in_array($nuevo, $this->siguientesPermitidos(), true);
    }
}