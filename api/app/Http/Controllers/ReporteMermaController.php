<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Merma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reportes de merma.
 *
 * SOLO `encargado` y `administrador`.
 *
 * El reporte responde a la pregunta que se hace al cerrar el dia: "cuanto
 * perdi, en que se perdio y de quien fue". Por eso separa `manual` de
 * `automatica`: la perdida que vino del reloj es distinta de la que vino de
 * las manos, y mezclarlas no deja actuar.
 *
 * Lo que NO hace, y es deliberado: no calcula un costo de perdida con el
 * `precio_venta` de los insumos. El precio de venta de un insumo es el
 * precio de la bolsa que se vende, no lo que costo comprarla, y usar uno
 * como si fuera el otro daria una cifra inventada. Para eso hace falta un
 * precio de costo que la panaderia todavia no ha definido. Ver docs/10.
 */
class ReporteMermaController extends Controller
{
    public function indice(): JsonResponse
    {
        $this->authorize('reporte', Merma::class);

        return response()->json([
            'mensaje' => 'Usa por-motivo o por-producto. El indice es deliberadamente un indice.',
        ]);
    }

    /** Cuanta merma hubo, agrupada por motivo. */
    public function porMotivo(Request $request): JsonResponse
    {
        $this->authorize('reporte', Merma::class);

        [$desde, $hasta] = $this->rango($request);

        $filas = Merma::query()
            ->join('motivos_merma', 'mermas.motivo_merma_id', '=', 'motivos_merma.id')
            ->whereBetween('mermas.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('motivos_merma.id', 'motivos_merma.codigo', 'motivos_merma.nombre')
            ->selectRaw('motivos_merma.codigo, motivos_merma.nombre')
            ->selectRaw('SUM(mermas.cantidad) AS cantidad')
            ->selectRaw("SUM(CASE when mermas.origen = 'automatica' THEN mermas.cantidad ELSE 0 END) AS automatica")
            ->selectRaw("SUM(CASE when mermas.origen = 'manual' THEN mermas.cantidad ELSE 0 END) AS manual")
            ->orderByDesc('cantidad')
            ->get();

        $total = (float) $filas->sum('cantidad');

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'total_unidades' => $total,
            'por_motivo' => $filas->map(fn ($f) => [
                'codigo' => $f->codigo,
                'nombre' => $f->nombre,
                'cantidad' => (float) $f->cantidad,
                'manual' => (float) $f->manual,
                'automatica' => (float) $f->automatica,
                // Que porcentaje del total es este motivo. `NULL` cuando no
                // hubo ninguna merma en el periodo, para no inventar un 0 %
                // que parece un dato.
                'porcentaje' => $total > 0 ? round(((float) $f->cantidad / $total) * 100, 2) : null,
            ]),
        ]);
    }

    /** Cuanta merma hubo, agrupada por producto. */
    public function porProducto(Request $request): JsonResponse
    {
        $this->authorize('reporte', Merma::class);

        [$desde, $hasta] = $this->rango($request);

        $filas = Merma::query()
            ->join('articulos', 'mermas.articulo_id', '=', 'articulos.id')
            ->whereBetween('mermas.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('articulos.id', 'articulos.nombre', 'articulos.precio_venta')
            ->selectRaw('articulos.nombre, articulos.precio_venta')
            ->selectRaw('SUM(mermas.cantidad) AS cantidad')
            ->orderByDesc('cantidad')
            ->limit(50)
            ->get();

        $total = (float) $filas->sum('cantidad');

        return response()->json([
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'total_unidades' => $total,
            'por_producto' => $filas->map(fn ($f) => [
                'nombre' => $f->nombre,
                'cantidad' => (float) $f->cantidad,
                'porcentaje' => $total > 0 ? round(((float) $f->cantidad / $total) * 100, 2) : null,
                // NULL si el articulo no tiene precio cargado. Inventar un
                // valor seria peor que admitir que no se sabe.
                'precio_venta' => $f->precio_venta === null ? null : (float) $f->precio_venta,
                'valor_perdido' => $f->precio_venta === null
                    ? null
                    : round(((float) $f->cantidad * (float) $f->precio_venta), 2),
            ]),
        ]);
    }

    /**
     * El rango del reporte.
     *
     * Por omision, el mes en curso. Si viene `desde` y `hasta`, se respetan:
     * el llamador sabe lo que pide.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rango(Request $request): array
    {
        $desde = $request->filled('desde')
            ? Carbon::parse($request->query('desde'))
            : Carbon::today()->startOfMonth();

        $hasta = $request->filled('hasta')
            ? Carbon::parse($request->query('hasta'))
            : Carbon::today();

        if ($desde->greaterThan($hasta)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return [$desde, $hasta];
    }
}