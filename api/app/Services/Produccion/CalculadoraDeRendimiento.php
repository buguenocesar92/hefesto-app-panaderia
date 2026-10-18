<?php

declare(strict_types=1);

namespace App\Services\Produccion;

use App\Exceptions\ErrorDePanaderia;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\Receta;
use App\Models\RecetaIngrediente;

/**
 * Calcula cuanto rinde una receta y cuanto hay que gastar para conseguir esa
 * cantidad.
 *
 * ESTA ES LA PREGUNTA DEL DIA A DIA DEL HORNO
 * -------------------------------------------
 * "Si hago dos tandas de marraquetas, cuanta harina saco del almacen y
 * cuantos panes me quedan?"
 *
 * Se responde con DOS cuentas que van en sentidos distintos y por eso es
 * facil confundirlas:
 *
 *  - `rendimiento`: lo que SALE. Multiplica por el numero de tandas.
 *  - `cantidad_total`: lo que ENTRA, ya con la merma estimada del insumo. Si
 *    la receta pide 1000 gramos y la merma es 5 %, hay que sacar 1050: no
 *    1000, o al final faltaria harina para el ultimo pan.
 *
 * POR QUE NO CALCULA COSTO
 * -----------------------
 * Porque no hay dato de precio de costo, y el `precio_venta` de un insumo es
 * el precio de la bolsa que se vende al publico, NO lo que costo comprarla.
 * Multiplicar uno por otro daria un costo de produccion inventado, y un
 * numero falso es peor que un numero ausente. Queda como pendiente en
 * docs/10. Ver docs/02-MODELO-DE-DATOS.md.
 */
class CalculadoraDeRendimiento
{
    /**
     * Cuanto rinde, y cuanto hay que gastar, para N tandas.
     *
     * @return array{
     *     receta_id: string,
     *     numero_tandas: int,
     *     rendimiento_tanda: float,
     *     unidad_rendimiento: string,
     *     rendimiento_total: float,
     *     insumos: list<array<string, mixed>>,
     *     alcanza: bool
     * }
     */
    public function paraTandas(Receta $receta, int $numeroTandas, ?callable $stockDe = null): array
    {
        if ($numeroTandas < 1) {
            // Media tanda no existe en una panaderia: o se hace una corrida o
            // no se hace. Ademas, un cero devolveria un rendimiento de cero
            // sin avisar.
            throw ErrorDePanaderia::numeroTandasInvalido();
        }

        $stockDe ??= fn (Articulo $a): float => (float) MovimientoInventario::query()
            ->where('articulo_id', $a->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN sentido = 'entrada' THEN cantidad ELSE -cantidad END), 0) AS saldo")
            ->value('saldo');

        $ingredientes = $receta->ingredientes()->with(['insumo', 'unidad'])->get();

        $rendimientoTanda = (float) $receta->rendimiento_cantidad;

        $insumos = [];

        foreach ($ingredientes as $ingrediente) {
            /** @var RecetaIngrediente $ingrediente */
            $insumo = $ingrediente->insumo;

            if ($insumo === null) {
                // Una receta que apunta a un insumo borrado es una receta
                // rota. Fallar aqui es mejor que devolver una cantidad en
                // cero que alguien va a gastar de mas en el almacen.
                throw ErrorDePanaderia::insumoInexistente($receta->nombre);
            }

            // Se incluye la merma estimada: es la diferencia entre lo que dice
            // la receta y lo que hay que sacar del almacen.
            $cantidadTotal = $ingrediente->cantidadTotal() * $numeroTandas;
            $stock = $stockDe($insumo);

            $insumos[] = [
                'insumo_id' => $insumo->id,
                'nombre' => $insumo->nombre,
                'unidad' => $ingrediente->unidad?->codigo ?? 'unidad_base',
                'cantidad_por_tanda' => (float) $ingrediente->cantidadTotal(),
                'merma_porcentaje' => (float) $ingrediente->merma_porcentaje,
                'cantidad_total' => $cantidadTotal,
                'stock_disponible' => $stock,
                // Tolerancia de 0,000001: son kilos, y una division de mas
                // no significa que falte harina.
                'alcanza' => $stock + 0.000001 >= $cantidadTotal,
            ];
        }

        $alcanza = true;

        foreach ($insumos as $insumo) {
            if ($insumo['alcanza'] === false) {
                $alcanza = false;
                break;
            }
        }

        return [
            'receta_id' => $receta->id,
            'numero_tandas' => $numeroTandas,
            'rendimiento_tanda' => $rendimientoTanda,
            'unidad_rendimiento' => $receta->unidadRendimiento?->codigo ?? 'unidad_base',
            'rendimiento_total' => $rendimientoTanda * $numeroTandas,
            'insumos' => $insumos,
            'alcanza' => $alcanza,
        ];
    }

    /**
     * Que insumos NO alcanzan, y por cuanto.
     *
     * La interfaz lo muestra antes de que el hornero arranque el horno: es
     * mejor enterarse de que faltan 3 kilos de harina que a mitad de la
     * tanda.
     *
     * @param  array<string, mixed>  $rendimiento
     * @return list<array{nombre: string, falta: float, unidad: string}>
     */
    public function faltantes(array $rendimiento): array
    {
        $faltantes = [];

        foreach ($rendimiento['insumos'] as $insumo) {
            if ($insumo['alcanza'] === true) {
                continue;
            }

            $faltantes[] = [
                'nombre' => $insumo['nombre'],
                'falta' => round($insumo['cantidad_total'] - $insumo['stock_disponible'], 6),
                'unidad' => $insumo['unidad'],
            ];
        }

        return $faltantes;
    }
}