<?php

declare(strict_types=1);

namespace App\Services\Ventas;

use App\Enums\EstadoLote;
use App\Models\Articulo;
use App\Models\LoteProducto;

/**
 * Elige de que lote sale la mercaderia que se esta vendiendo.
 *
 * POR QUE EL MAS ANTIGUO PRIMERO
 * ------------------------------
 * Se vende primero lo que vence antes. Es la regla que evita que la merma
 * crezca sola: si se vendiera lo mas nuevo, lo viejo se quedaria en el
 * mostrador hasta pudrirse.
 *
 * En PostgreSQL el orden se escribe en la consulta, con una sola excepcion
 * que importa: los lotes SIN fecha de vencimiento van AL FINAL.
 *
 * `fecha_vencimiento ASC NULLS LAST` es lo que hace falta: sin ese `NULLS
 * LAST`, PostgreSQL pone los nulos primero por omision, y como NULL es lo
 * mas pequeno, el pan sin regla de vencimiento se venderia SIEMPRE primero.
 * Seria lo contrario de lo razonable: lo que no caduca es lo que mas se
 * puede guardar.
 */
class SelectorPorVencimiento
{
    /**
     * Los lotes de un articulo que se pueden vender, del que vence antes al
     * que vence despues.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, LoteProducto>
     */
    public function lotesVendibles(Articulo $articulo)
    {
        return LoteProducto::query()
            ->where('articulo_id', $articulo->id)
            ->where('estado', EstadoLote::DISPONIBLE)
            ->where('cantidad_disponible', '>', 0)
            ->orderByRaw('fecha_vencimiento ASC NULLS LAST')
            ->orderBy('fecha_produccion')
            ->get();
    }

    /**
     * De donde sale exactamente esta cantidad de este articulo, repartida en
     * los lotes que hagan falta.
     *
     * Se reparte entre varios lotes a proposito: si el cliente pide 12 y en
     * el mostrador hay 8 del lote de ayer y 20 del de hoy, se venden los 8
     * viejos y 4 nuevos. No se le dice que no hay.
     *
     * @return array{lotes: list<array{lote: LoteProducto, cantidad: float}>, total: float, faltan: float}
     */
    public function repartir(Articulo $articulo, float $cantidad): array
    {
        $lotes = [];
        $faltan = $cantidad;
        $total = 0.0;

        foreach ($this->lotesVendibles($articulo) as $lote) {
            /** @var LoteProducto $lote */
            $disponible = (float) $lote->cantidad_disponible;

            if ($faltan <= 0) {
                break;
            }

            $tomado = min($disponible, $faltan);

            $lotes[] = [
                'lote' => $lote,
                'cantidad' => $tomado,
            ];

            $total += $tomado;
            $faltan -= $tomado;
        }

        return [
            'lotes' => $lotes,
            'total' => $total,
            // Lo que no se pudo cubrir. Si es mayor que cero, el mostrador
            // tiene que avisar antes de cobrar, no despues.
            'faltan' => round(max(0, $faltan), 6),
        ];
    }
}