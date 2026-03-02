<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class TipoCambioMoneda extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'moneda_origen',
        'moneda_destino',
        'valor_api',
        'porcentaje_margen',
    ];

    protected function casts(): array
    {
        return [
            'valor_api'         => 'decimal:4',
            'porcentaje_margen' => 'decimal:2',
            'valor_final'       => 'decimal:4',
            'moneda_origen'     => 'string',
            'moneda_destino'    => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TipoCambioMoneda $model) {
            $model->valor_final = self::calcularValorFinal(
                (float) $model->valor_api,
                (float) ($model->porcentaje_margen ?? 2.00)
            );
        });

        static::updating(function (TipoCambioMoneda $model) {
            if ($model->isDirty(['valor_api', 'porcentaje_margen'])) {
                $model->valor_final = self::calcularValorFinal(
                    (float) $model->valor_api,
                    (float) ($model->porcentaje_margen ?? 2.00)
                );
            }
        });
    }

    private static function calcularValorFinal(float $valorApi, float $margen): float
    {
        return round($valorApi * (1 + ($margen / 100)), 4);
    }

    public static function actual(): float
    {
        return (float) Cache::remember('tipo_cambio_mxn', now()->addHours(24), function () {
            return self::latest('id')->value('valor_final') ?? 20.00;
        });
    }

    public static function refrescarCache(): float
    {
        Cache::forget('tipo_cambio_mxn');
        return self::actual();
    }
}