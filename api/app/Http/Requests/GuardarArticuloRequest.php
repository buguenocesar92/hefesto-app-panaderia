<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Articulo;

class GuardarArticuloRequest extends PeticionDePanaderia
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $articuloId = $this->route('articulo')?->id;

        return [
            'sku' => ['required', 'string', 'max:48',
                // Unico DENTRO de la panaderia, no en toda la instalacion.
                \Illuminate\Validation\Rule::unique('articulos', 'sku')
                    ->where('tenant_id', $this->usuario()->tenant_id)
                    ->ignore($articuloId),
            ],
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'unidad_base_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'unidad_compra_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'unidad_venta_id' => ['required', 'uuid', 'exists:unidades_medida,id'],

            // El precio se puede dejar sin cargar. Es una decision de la
            // panaderia, no un dato que el sistema pueda deducir.
            'precio_venta' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],

            'se_vende' => ['sometimes', 'boolean'],
            'es_insumo' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('precio_venta') && $this->input('precio_venta') === '') {
            $this->merge(['precio_venta' => null]);
        }

        if ($this->has('stock_minimo') && $this->input('stock_minimo') === '') {
            $this->merge(['stock_minimo' => null]);
        }
    }

    public function messages(): array
    {
        return $this->mensajes();
    }

    public function attributes(): array
    {
        return $this->atributos();
    }

    public function datos(): array
    {
        return $this->safe()->except(['precio_venta', 'stock_minimo']);
    }

    private function usuario(): \App\Models\Usuario
    {
        return $this->user();
    }
}