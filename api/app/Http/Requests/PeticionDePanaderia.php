<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clase base de las peticiones de la panaderia.
 *
 * `authorize()` delega en la Policy del modelo. Cuando la Policy rechaza,
 * `bootstrap/app.php` lo convierte en 404 y no en 403: un 403 confirmaria
 * que el recurso existe, y eso ya es filtrar informacion.
 */
abstract class PeticionDePanaderia extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Los mensajes en el mismo estilo del resto del sistema: en espanol, y
     * diciendo QUE hacer, no solo que algo esta mal.
     *
     * @return array<string, string>
     */
    protected function mensajes(): array
    {
        return [
            'required' => 'Falta el campo :attribute.',
            'numeric' => 'El campo :attribute debe ser un numero.',
            'integer' => 'El campo :attribute debe ser un numero entero.',
            'min' => [
                'numeric' => 'El campo :attribute no puede ser menor que :min.',
                'array' => 'El campo :attribute necesita al menos :min elemento(s).',
            ],
            'array' => 'El campo :attribute debe ser una lista.',
            'boolean' => 'El campo :attribute debe ser si o no.',
            'date' => 'El campo :attribute debe ser una fecha.',
            'date_format' => 'El campo :attribute no tiene el formato de fecha esperado.',
            'exists' => 'El campo :attribute no corresponde a un registro existente.',
            'unique' => 'Ya existe un registro con ese valor en :attribute.',
        ];
    }

    /**
     * Los atributos con nombre de verdad, para que el mensaje no diga
     * "el campo sku" sino "el codigo del articulo".
     *
     * @return array<string, string>
     */
    protected function atributos(): array
    {
        return [
            'sku' => 'codigo del articulo',
            'nombre' => 'nombre',
            'unidad_base_id' => 'unidad base',
            'unidad_compra_id' => 'unidad de compra',
            'unidad_venta_id' => 'unidad de venta',
            'precio_venta' => 'precio de venta',
            'stock_minimo' => 'stock minimo',
            'articulo_id' => 'articulo',
            'receta_id' => 'receta',
            'cantidad' => 'cantidad',
            'cantidad_producida' => 'cantidad producida',
            'numero_tandas' => 'numero de tandas',
            'ubicacion_id' => 'ubicacion',
            'motivo_merma_id' => 'motivo de la merma',
            'lote_producto_id' => 'lote',
            'merma_porcentaje' => 'merma estimada',
            'rendimiento_cantidad' => 'cantidad que rinde la receta',
            'rendimiento_unidad_id' => 'unidad del rendimiento',
            'dias_vigencia' => 'dias de vigencia',
            'descuento_porcentaje' => 'porcentaje de descuento',
            'fecha_entrega' => 'fecha de entrega',
            'hora_entrega' => 'hora de entrega',
            'cliente_nombre' => 'nombre del cliente',
            'cliente_contacto' => 'contacto del cliente',
            'monto_total' => 'monto total',
            'anticipo' => 'anticipo',
            'tamano' => 'tamano',
            'items' => 'productos de la venta',
            'items.*.articulo_id' => 'articulo',
            'items.*.cantidad' => 'cantidad',
        ];
    }
}