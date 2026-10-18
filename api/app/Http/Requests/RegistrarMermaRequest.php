<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrigenMerma;

/**
 * Registrar una merma.
 *
 * `motivo_merma_id` es `required` y no `nullable`. Es el campo mas
 * importante de todo el formulario: una merma sin motivo es exactamente la
 * perdida invisible que el sistema existe para hacer visible.
 */
class RegistrarMermaRequest extends PeticionDePanaderia
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'articulo_id' => ['required', 'uuid', 'exists:articulos,id'],
            'lote_producto_id' => ['nullable', 'uuid', 'exists:lote_productos,id'],

            // Obligatoria, sin excepcion.
            'motivo_merma_id' => ['required', 'uuid', 'exists:motivos_merma,id'],

            'cantidad' => ['required', 'numeric', 'gt:0'],
            'fecha' => ['sometimes', 'date'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return $this->mensajes() + [
            'cantidad.gt' => 'La cantidad de la merma tiene que ser mayor que cero.',
            'motivo_merma_id.required' => 'Una merma tiene que decir por que se perdio la mercaderia.',
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