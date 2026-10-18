<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Articulo;
use App\Models\Ubicacion;
use Illuminate\Validation\Rule;

/**
 * Registrar una corrida terminada.
 *
 * LAS REGLAS MAS IMPORTANTES
 * -------------------------
 *  - `cantidad_producida` tiene que ser mayor que cero. Una tanda que no
 *    produjo nada no es una tanda, y crearla deja un lote fantasma.
 *  - `numero_tandas` es al menos 1, porque el rendimiento y el consumo de
 *    insumos se calculan sobre esa cantidad.
 */
class RegistrarTandaRequest extends PeticionDePanaderia
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cantidad_producida' => ['required', 'numeric', 'gt:0'],
            'numero_tandas' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'ubicacion_id' => ['required', 'uuid', 'exists:ubicaciones,id'],
            'fecha_produccion' => ['sometimes', 'date'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return $this->mensajes() + [
            'cantidad_producida.gt' => 'La cantidad producida tiene que ser mayor que cero.',
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