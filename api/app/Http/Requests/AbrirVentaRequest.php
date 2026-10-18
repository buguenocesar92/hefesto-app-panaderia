<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Abrir una venta de mostrador.
 *
 * `items.*.cantidad` tiene que ser mayor que cero: una linea con cantidad 0
 * crea una linea de venta que no vende nada y ensucia el arqueo.
 */
class AbrirVentaRequest extends PeticionDePanaderia
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.articulo_id' => ['required', 'uuid', 'exists:articulos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],

            'lote_cierre_id' => ['nullable', 'uuid', 'exists:lotes_cierre,id'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return $this->mensajes() + [
            'items.min' => 'Una venta tiene que tener al menos un producto.',
            'items.*.cantidad.gt' => 'La cantidad de cada producto tiene que ser mayor que cero.',
        ];
    }

    public function attributes(): array
    {
        return $this->atributos();
    }

    /** @return array<string, mixed> */
    public function datos(): array
    {
        return $this->validated();
    }
}