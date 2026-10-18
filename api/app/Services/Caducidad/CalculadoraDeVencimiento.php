<?php

declare(strict_types=1);

namespace App\Services\Caducidad;

use App\Models\Articulo;
use App\Models\Vigencia;
use Illuminate\Support\Carbon;

/**
 * Dice cuando vence un lote, usando la regla que la panaderia configuro.
 *
 * LA REGLA MAS IMPORTANTE DE ESTE ARCHIVO
 * --------------------------------------
 * Si el producto NO tiene regla de vencimiento, este metodo devuelve NULL.
 * No devuelve "hoy", ni "manana", ni el valor por omision de la configuracion.
 *
 * La tentacion es poner un plazo por omision para que la columna nunca este
 * vacia. Es un error: un plazo inventado es una regla de negocio falsa que
 * despues aparece en un informe como si alguien la hubiera decidido. Un
 * NULL es honesto y es visible, y la panaderia lo ve en pantalla como "este
 * producto no tiene regla" y lo configura.
 *
 * Ver docs/05-CONFIGURACION.md.
 */
class CalculadoraDeVencimiento
{
    /**
     * La fecha de vencimiento de un lote, o NULL si no hay regla.
     *
     * @param  bool  $porOmision  Si el producto no tiene fila propia en
     *                           `vigencias`, se usa el valor por omision de
     *                           `config/panaderia.php`. Ese valor es NULL a
     *                           proposito.
     */
    public function para(Articulo $articulo, Carbon $fechaProduccion, bool $porOmision = true): ?Carbon
    {
        $vigencia = $this->reglaDe($articulo, $porOmision);

        if ($vigencia === null) {
            return null;
        }

        return $fechaProduccion->copy()->addDays($vigencia);
    }

    /**
     * La regla en dias, o NULL si el producto no tiene.
     *
     * Primero se busca la fila propia del producto. Solo si no existe, y si
     * el producto no trae la suya embebida, se mira la configuracion global.
     */
    public function reglaDe(Articulo $articulo, bool $porOmision = true): ?int
    {
        $vigencia = $articulo->relationLoaded('vigencia')
            ? $articulo->vigencia
            : Vigencia::query()->where('articulo_id', $articulo->id)->first();

        if ($vigencia !== null) {
            // Una fila con `dias_vigencia` en NULL dice "este producto no
            // caduca". Es una decision del negocio y gana sobre el valor por
            // omision: si el administrador la escribio, es porque quiere que
            // ese producto en particular no caduque.
            return $vigencia->dias_vigencia;
        }

        if (! $porOmision) {
            return null;
        }

        $diasPorDefecto = config('panaderia.caducidad.dias_por_defecto');

        return $diasPorDefecto === null ? null : (int) $diasPorDefecto;
    }

    /** La merma de un lote vencido se puede automatizar o no. */
    public function mermaAutomatica(Articulo $articulo): bool
    {
        $vigencia = $articulo->relationLoaded('vigencia')
            ? $articulo->vigencia
            : Vigencia::query()->where('articulo_id', $articulo->id)->first();

        return $vigencia?->merma_automatica ?? false;
    }

    /** Que productos no tienen regla, para que el encargado lo vea. */
    public function sinRegla(): array
    {
        return Articulo::query()
            ->where('activo', true)
            ->whereDoesntHave('vigencia')
            ->orderBy('nombre')
            ->get(['id', 'sku', 'nombre'])
            ->all();
    }
}