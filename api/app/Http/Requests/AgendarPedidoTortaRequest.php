<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MetodoPago;
use App\Enums\EstadoPedidoTorta;

/**
 * Agendar un pedido de torta.
 *
 * `monto_total` es obligatorio a proposito: un pedido sin monto no se puede
 * saber cuanto falta cobrar, y es la venta de mayor valor del negocio.
 */
class AgendarPedidoTortaRequest extends PeticionDePanaderia
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cliente_nombre' => ['required', 'string', 'max:160'],
            'cliente_contacto' => ['nullable', 'string', 'max:160'],
            'articulo_id' => ['required', 'uuid', 'exists:articulos,id'],
            'cantidad' => ['sometimes', 'integer', 'min:1', 'max:999'],

            // Tamano y sabor son texto libre: cada panaderia los llama
            // distinto y una tabla de tamanos obligaria a elegir uno.
            'tamano' => ['nullable', 'string', 'max:64'],
            'sabor' => ['nullable', 'string', 'max:120'],
            'diseno' => ['nullable', 'string', 'max:4000'],
            'mensaje_personalizado' => ['nullable', 'string', 'max:1000'],

            'fecha_entrega' => ['required', 'date'],
            'hora_entrega' => ['nullable', 'string', 'max:5'],
            'monto_total' => ['required', 'numeric', 'min:0'],
            'anticipo' => ['sometimes', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return $this->mensajes() + [
            'monto_total.required' => 'El pedido necesita un monto total: es la venta de mayor valor del negocio.',
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