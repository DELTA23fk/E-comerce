<?php

namespace App\Http\Requests\Admin\Pedido;

use App\Enum\Order\ProveedorOrderStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
 
class ActualizarPedidoProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
 
    public function rules(): array
    {
        return [
            'status'                 => ['nullable', Rule::enum(ProveedorOrderStatusEnum::class)],
            'fecha_entrega_estimada' => ['nullable', 'date', 'after_or_equal:today'],
            'email_agente'           => ['nullable', 'email', 'max:255'],
            'email_almacen'          => ['nullable', 'email', 'max:255'],
            'origen_envio'           => ['nullable', 'string', 'max:255'],
            'folio_pedido'           => ['nullable', 'string', 'max:255'],
            'moneda_cobro_productos' => ['nullable', 'string', 'max:10'],
            'precio_total_productos' => ['nullable', 'numeric', 'min:0'],
            'moneda_cobro_envio'    => ['nullable', 'string', 'max:10'],
            'precio_total_envio'    => ['nullable', 'numeric', 'min:0'],
            'precio_total'          => ['nullable', 'numeric', 'min:0'],
            'iva_incluido'          => ['nullable', 'boolean'],
            'envio_gratis'          => ['nullable', 'boolean'],
            'tipo_cambio_aplicado'    => ['nullable', 'numeric', 'min:0'],
            'precio_total_productos_mxn' => ['nullable', 'numeric', 'min:0'],
            'precio_total_envio_mxn'    => ['nullable', 'numeric', 'min:0'],
            'precio_total_mxn'          => ['nullable', 'numeric', 'min:0'],
            'fecha_reembolso'          => ['nullable', 'date'],
            'monto_reembolsado_mxn'    => ['nullable', 'numeric', 'min:0'],
            'motivo_reembolso'         => ['nullable', 'string', 'max:255'],
            'error_mensaje'           => ['nullable', 'string', 'max:1000']
        ];
    }
 
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $allNull = collect($this->only([
                'status', 'fecha_entrega_estimada', 'email_agente', 'email_almacen', 'origen_envio',
            ]))->filter()->isEmpty();
 
            if ($allNull) {
                $v->errors()->add('general', 'Debes enviar al menos un campo para actualizar.');
            }
        });
    }
 
    public function messages(): array
    {
        return [
            'status.enum'                       => 'El status de proveedor no es válido. Valores permitidos: '
                                                   . implode(', ', ProveedorOrderStatusEnum::values()),
            'fecha_entrega_estimada.after_or_equal' => 'La fecha de entrega estimada no puede ser en el pasado.',
            'email_agente.email'                => 'El email del agente no tiene un formato válido.',
            'email_almacen.email'               => 'El email del almacén no tiene un formato válido.',
        ];
    }
}
