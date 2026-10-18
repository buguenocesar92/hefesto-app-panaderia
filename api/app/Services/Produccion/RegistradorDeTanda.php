<?php

declare(strict_types=1);

namespace App\Services\Produccion;

use App\Enums\EstadoLote;
use App\Enums\EstadoTanda;
use App\Enums\TipoMovimiento;
use App\Exceptions\ErrorDePanaderia;
use App\Models\LoteProducto;
use App\Models\Tanda;
use App\Models\Ubicacion;
use App\Services\Caducidad\CalculadoraDeVencimiento;
use App\Services\Inventario\RegistroDeMovimiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra una tanda de produccion: consume insumos y crea el lote.
 *
 * TODO PASA EN UNA SOLA TRANSACCION
 * --------------------------------
 * Si el horno se apaga a mitad del guardado, o el lote se crea pero los
 * insumos no se descuentan, el inventario queda mintiendo en una de las dos
 * mitades. Por eso el metodo abre su propia transaccion y escribe TODO o no
 * escribe nada.
 *
 * El orden importa: primero se descuentan los insumos y despues se crea el
 * lote. Al reves, un fallo dejaria pan en el almacen que nunca se hizo.
 */
class RegistradorDeTanda
{
    public function __construct(
        private readonly RegistroDeMovimiento $movimientos,
        private readonly CalculadoraDeRendimiento $rendimiento,
        private readonly CalculadoraDeVencimiento $vencimientos,
    ) {}

    /**
     * Registra la corrida terminada.
     *
     * @param  array{
     *     numero_tandas?: int,
     *     cantidad_producida: float,
     *     ubicacion_id: string,
     *     fecha_produccion?: string|null,
     *     notas?: string|null
     * }  $datos
     * @return array{tanda: Tanda, lote: LoteProducto, rendimiento: array<string, mixed>}
     */
    public function registrar(Tanda $tanda, array $datos): array
    {
        if ($tanda->estado->estaCerrada()) {
            throw ErrorDePanaderia::estadoInvalido(
                $tanda->estado->value,
                EstadoTanda::TERMINADA->value
            );
        }

        $numeroTandas = (int) ($datos['numero_tandas'] ?? 1);
        $cantidadProducida = (float) $datos['cantidad_producida'];

        if ($cantidadProducida <= 0) {
            throw new ErrorDePanaderia(
                'La cantidad producida tiene que ser mayor que cero.',
                'cantidad_invalida'
            );
        }

        $receta = $tanda->receta()->with(['ingredientes.insumo', 'ingredientes.unidad'])->firstOrFail();

        if ($receta->ingredientes->isEmpty()) {
            // Una receta sin ingredientes descontaria cero y crearia lotes de
            // pan hecho con nada. Falla antes.
            throw ErrorDePanaderia::recetaSinIngredientes($receta->nombre);
        }

        $rendimiento = $this->rendimiento->paraTandas($receta, $numeroTandas);

        if ($rendimiento['alcanza'] === false) {
            // Se avisa ANTES de tocar nada. Descontar la mitad de los
            // insumos y fallar despues dejaria el almacen con la receta a
            // medias y el hornero sin pan.
            $faltantes = $this->rendimiento->faltantes($rendimiento);
            $detalle = [];

            foreach ($faltantes as $faltante) {
                $detalle[] = "{$faltante['nombre']} (falta {$faltante['falta']} {$faltante['unidad']})";
            }

            throw new ErrorDePanaderia(
                'No alcanza el stock para esa tanda. Falta: ' . implode('; ', $detalle) . '.',
                'sin_insumos'
            );
        }

        $ubicacion = Ubicacion::query()->findOrFail($datos['ubicacion_id']);
        $fechaProduccion = isset($datos['fecha_produccion'])
            ? Carbon::parse($datos['fecha_produccion'])
            : Carbon::parse($tanda->fecha);

        $vencimiento = $this->vencimientos->para($receta->articulo, $fechaProduccion);

        return DB::transaction(function () use (
            $tanda,
            $receta,
            $rendimiento,
            $cantidadProducida,
            $ubicacion,
            $fechaProduccion,
            $vencimiento,
            $datos
        ): array {
            // 1. Descontar los insumos de la tanda.
            foreach ($rendimiento['insumos'] as $insumo) {
                $this->movimientos->salirInsumo([
                    'tenant_id' => $tanda->tenant_id,
                    'articulo_id' => $insumo['insumo_id'],
                    'cantidad' => $insumo['cantidad_total'],
                    'tipo' => TipoMovimiento::SALIDA,
                    // La referencia deja la pista de que consumo fue este y
                    // no una merma: el informe distingue las dos cosas.
                    'referencia' => $tanda->numero,
                    'usuario_id' => $tanda->hornero_id,
                    'notas' => "Consumo de la tanda {$tanda->numero}: {$insumo['nombre']}.",
                ]);
            }

            // 2. Crear el lote con lo que salio del horno.
            $lote = LoteProducto::query()->create([
                'tenant_id' => $tanda->tenant_id,
                'articulo_id' => $receta->articulo_id,
                'ubicacion_id' => $ubicacion->id,
                'tanda_id' => $tanda->id,
                'codigo' => $this->codigoDeLote($tanda, $fechaProduccion),
                'cantidad_inicial' => $cantidadProducida,
                // El lote nace con su saldo completo. El movimiento de
                // entrada de abajo es la UNICA forma de que ese numero
                // exista, y por eso el mismo gatillo que impide editar el
                // saldo permite crearlo.
                'cantidad_disponible' => $cantidadProducida,
                'fecha_produccion' => $fechaProduccion->toDateString(),
                'fecha_vencimiento' => $vencimiento?->toDateString(),
                'estado' => EstadoLote::DISPONIBLE,
            ]);

            // 3. Registrar la entrada del producto horneado.
            $this->movimientos->entrar(
                [
                    'tenant_id' => $tanda->tenant_id,
                    'articulo_id' => $receta->articulo_id,
                    'cantidad' => $cantidadProducida,
                    'ubicacion_id' => $ubicacion->id,
                    'tipo' => TipoMovimiento::INGRESO,
                    'referencia' => $tanda->numero,
                    'usuario_id' => $tanda->hornero_id,
                    'notas' => "Produccion de la tanda {$tanda->numero}.",
                ],
                $lote
            );

            // 4. Cerrar la tanda con la realidad, no con lo planificado.
            $tanda->cantidad_producida = $cantidadProducida;
            $tanda->estado = EstadoTanda::TERMINADA;
            $tanda->cerrada_en = now();
            $tanda->notas = $datos['notas'] ?? $tanda->notas;
            $tanda->save();

            return [
                'tanda' => $tanda->fresh(),
                'lote' => $lote->fresh(),
                'rendimiento' => $rendimiento,
            ];
        });
    }

    /**
     * El codigo del lote: `LOTE-<numero de tanda>-<fecha>`.
     *
     * Va con el numero de tanda y no con un correlativo suelto porque es lo
     * que permite reconstruir de donde salio una mercaderia leyendo el
     * codigo, sin consultar la base. Es el mismo numero de tanda, asi que no
     * hay dos formas de nombrar la misma corrida.
     */
    private function codigoDeLote(Tanda $tanda, Carbon $fecha): string
    {
        return sprintf('LOTE-%s-%s', $tanda->numero, $fecha->format('Ymd'));
    }
}