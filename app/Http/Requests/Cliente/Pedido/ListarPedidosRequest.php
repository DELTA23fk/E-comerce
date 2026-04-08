<?php

namespace App\Http\Requests\Cliente\Pedido;

use App\Enum\Order\OrderStatusEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListarPedidosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
 
    public function rules(): array
    {
        return [
            'estatus'        => ['nullable', Rule::enum(OrderStatusEnum::class)],
            'folio'          => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'in:pending,approved,rejected,refunded,partial_refunded'],
            'fecha_desde'    => ['nullable', 'date', 'before_or_equal:fecha_hasta'],
            'fecha_hasta'    => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'per_page'       => ['nullable', 'integer', 'min:5', 'max:100'],
            'order_by'       => ['nullable', 'string', 'in:fecha_pedido,precio_total,estatus'],
            'order_dir'      => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
 
    public function messages(): array
    {
        return [
            'estatus.enum'              => 'El estatus indicado no es válido.',
            'fecha_desde.before_or_equal' => 'La fecha inicial no puede ser mayor a la fecha final.',
            'fecha_hasta.after_or_equal'  => 'La fecha final no puede ser menor a la fecha inicial.',
            'per_page.max'              => 'Máximo 100 registros por página.',
        ];
    }
}
