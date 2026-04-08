<?php

namespace App\Http\Requests\Admin\Pedido;

use Illuminate\Foundation\Http\FormRequest;
 
class ActualizarFechaEntregaMasivaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
 
    public function rules(): array
    {
        return [
            'ids'                    => ['required', 'array', 'min:1', 'max:200'],
            'ids.*'                  => ['required', 'integer', 'exists:pedido_proveedores,id'],
            'fecha_entrega_estimada' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
 
    public function messages(): array
    {
        return [
            'ids.required'                      => 'Debes proporcionar al menos un ID.',
            'ids.*.exists'                      => 'Uno o más IDs de pedido proveedor no existen.',
            'fecha_entrega_estimada.required'   => 'La fecha de entrega es obligatoria.',
            'fecha_entrega_estimada.after_or_equal' => 'La fecha de entrega estimada no puede ser en el pasado.',
        ];
    }
}
