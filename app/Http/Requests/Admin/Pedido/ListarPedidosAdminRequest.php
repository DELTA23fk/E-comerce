<?php

namespace App\Http\Requests\Admin\Pedido;

use App\Enum\Order\OrderStatusEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListarPedidosAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Ajustar a tu guard/política de admin
    }
 
    public function rules(): array
    {
        return [
            'estatus'        => ['nullable', Rule::enum(OrderStatusEnum::class)],
            'folio'          => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'in:pending,approved,rejected,refunded,partial_refunded'],
            'fecha_desde'    => ['nullable', 'date', 'before_or_equal:fecha_hasta'],
            'fecha_hasta'    => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'per_page'       => ['nullable', 'integer', 'min:5', 'max:200'],
            'order_by'       => ['nullable', 'string', 'in:fecha_pedido,precio_total,estatus,cliente_id,updated_at'],
            'order_dir'      => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}

