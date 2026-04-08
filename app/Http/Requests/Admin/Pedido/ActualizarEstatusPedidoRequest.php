<?php

namespace App\Http\Requests\Admin\Pedido;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest; 
use App\Enum\Order\OrderStatusEnum;
use Illuminate\Validation\Rule;
 
class ActualizarEstatusPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
 
    public function rules(): array
    {
        return [
            'estatus'       => ['required', Rule::enum(OrderStatusEnum::class)],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }
 
    public function messages(): array
    {
        return [
            'estatus.required' => 'El estatus es obligatorio.',
            'estatus.enum'     => 'El estatus indicado no es válido. Valores permitidos: '
                                  . implode(', ', OrderStatusEnum::values()),
        ];
    }
}
