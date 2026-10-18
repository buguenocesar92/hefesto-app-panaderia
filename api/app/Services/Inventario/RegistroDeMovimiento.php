<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\EstadoLote;
use App\Enums\SentidoMovimiento;
use App\Enums\TipoMovimiento;
use App\Exceptions\ErrorDePanaderia;
use App\Models\LoteProducto;
use App\Models\MovimientoInventario;
use App\Models\Ubicacion;
use Illuminate\Database\ConnectionInterface;

/**
 * El UNICO servicio que puede cambiar el saldo de un lote.
 *
 * ESTA ES LA PIEZA QUE SOSTIENE TODAS LAS GARANTIAS
 * -------------------------------------------------
 * Un `UPDATE` directo sobre `lote_productos.cantidad_disponible` lo bloquea
 * un gatillo de PostgreSQL. Para pasar el filtro hay que poner la marca
 * `app.movimiento_inventario`, y la marca se pone con `set_config(...,
 * true)`, es decir con alcance de TRANSACCION.
 *
 * Esa combinacion da tres cosas de una:
 *
 *  1. Ningun saldo se inventa: o hay un movimiento escrito, o el UPDATE
 *     revienta.
 *  2. Los movimientos son inmutables: hay otro gatillo que prohibe
 *     modificarlos y borrarlos.
 *  3. El saldo nunca queda negativo: hay un tercer gatillo para eso.
 *
 * Ademas, como la marca es de transaccion, desaparece sola al terminar. No
 * puede quedar pegada a una conexion del pool y "+prestar" el permiso a la
 * peticion siguiente.
 */
class RegistroDeMovimiento
{
    public function __construct(private readonly ConnectionInterface $conexion) {}

    /**
     * Entra mercaderia: compra, o producto recien horneado.
     *
     * @param  array{articulo_id: string, cantidad: float, ubicacion_id?: string|null, lote_producto_id?: string|null, tipo?: TipoMovimiento, referencia?: string|null, usuario_id?: string|null, motivo_merma_id?: string|null, notas?: string|null}  $datos
     */
    public function entrar(array $datos, ?LoteProducto $lote = null): MovimientoInventario
    {
        return $this->escribir($datos, SentidoMovimiento::ENTRADA, $lote);
    }

    /**
     * Sale mercaderia: venta de mostrador, despacho, consumo de receta.
     *
     * El lote es obligatorio: sin el no se sabe cuando caduca lo que queda.
     *
     * @param  array{articulo_id: string, lote_producto_id: string, cantidad: float, tipo?: TipoMovimiento, referencia?: string|null, usuario_id?: string|null, notas?: string|null}  $datos
     */
    public function salir(array $datos): MovimientoInventario
    {
        $lote = LoteProducto::query()->findOrFail($datos['lote_producto_id']);

        if ($lote->estado === EstadoLote::MERMADO) {
            throw ErrorDePanaderia::loteAgotado($lote->codigo);
        }

        return $this->escribir($datos, SentidoMovimiento::SALIDA, $lote);
    }

    /**
     * Correccion de conteo. El unico caso en que el sentido se decide a mano.
     *
     * @param  array{articulo_id: string, cantidad: float, lote_producto_id?: string|null, ubicacion_id?: string|null, sentido: SentidoMovimiento, referencia?: string|null, usuario_id?: string|null, notas?: string|null}  $datos
     */
    public function ajustar(array $datos): MovimientoInventario
    {
        return $this->escribir(
            array_merge($datos, ['tipo' => TipoMovimiento::AJUSTE]),
            $datos['sentido'],
            isset($datos['lote_producto_id'])
                ? LoteProducto::query()->findOrFail($datos['lote_producto_id'])
                : null
        );
    }

    /**
     * Sale mercaderia A GRANEL: el insumo que se lleva una tanda.
     *
     * POR QUE NO LLEVA LOTE
     * ---------------------
     * Un insumo a granel no caduca por el día ni tiene una tanda de origen:
     * es harina de un patrocinador, no un pan de hoy. Por eso su salida se
     * registra en el libro pero sin lote, y su saldo se lleva con la misma
     * suma de movimientos que el resto.
     *
     * El producto horneado SI lleva lote: es el que tiene fecha de
     * vencimiento, y por eso necesita saber de que corrida salio.
     *
     * @param  array{articulo_id: string, cantidad: float, referencia?: string|null, usuario_id?: string|null, notas?: string|null}  $datos
     */
    public function salirInsumo(array $datos): MovimientoInventario
    {
        return $this->escribir($datos, SentidoMovimiento::SALIDA, null);
    }

    /**
     * El unico metodo que escribe. Todo lo demas pasa por aqui.
     *
     * @param  array<string, mixed>  $datos
     */
    private function escribir(array $datos, SentidoMovimiento $sentido, ?LoteProducto $lote): MovimientoInventario
    {
        $cantidad = (float) $datos['cantidad'];

        if ($cantidad <= 0) {
            // Un movimiento de cantidad cero o negativa no significa nada y
            // dejaria un ruido que despues nadie sabe interpretar.
            throw new ErrorDePanaderia(
                'La cantidad de un movimiento tiene que ser mayor que cero.',
                'cantidad_invalida'
            );
        }

        /** @var ConnectionInterface $conexion */
        $conexion = $this->conexion;

        return $conexion->transaction(function () use ($conexion, $datos, $sentido, $lote, $cantidad): MovimientoInventario {
            /*
             * La marca, con alcance de TRANSACCION. Se pone antes de tocar el
             * lote y desaparece sola en el COMMIT.
             */
            $conexion->statement("SELECT set_config('app.movimiento_inventario', '1', true)");

            if ($lote !== null) {
                $this->aplicarAlLote($lote, $sentido, $cantidad);
            }

            return MovimientoInventario::query()->create([
                'tenant_id' => $datos['tenant_id'] ?? $this->empresaDe($lote),
                'articulo_id' => $datos['articulo_id'],
                'lote_producto_id' => $lote?->id,
                'ubicacion_id' => $datos['ubicacion_id'] ?? $lote?->ubicacion_id,
                'tipo' => $datos['tipo'] ?? ($sentido === SentidoMovimiento::ENTRADA
                    ? TipoMovimiento::INGRESO
                    : TipoMovimiento::SALIDA),
                'sentido' => $sentido,
                'cantidad' => $cantidad,
                'motivo_merma_id' => $datos['motivo_merma_id'] ?? null,
                'referencia' => $datos['referencia'] ?? null,
                'usuario_id' => $datos['usuario_id'] ?? auth()->id(),
                'notas' => $datos['notas'] ?? null,
            ]);
        });
    }

    /**
     * De que panaderia es el movimiento.
     *
     * Cuando hay lote, el lote ya trae su `tenant_id`: es el dato mas
     * confiable porque no depende de que haya sesion. Eso es lo que permite
     * que el trabajo programado, que no tiene sesion, pueda registrar
     * mermas.
     *
     * En una peticion normal el lote tambien viene, asi que la sesion es
     * solo el respaldo para los movimientos de compra sin lote.
     */
    private function empresaDe(?LoteProducto $lote): string
    {
        $empresa = $lote?->tenant_id ?? auth()->user()?->tenant_id;

        if ($empresa === null) {
            throw new ErrorDePanaderia(
                'No se puede registrar un movimiento sin saber a que panaderia pertenece.',
                'sin_empresa'
            );
        }

        return $empresa;
    }

    /**
     * Baja o sube el saldo del lote y recalcula su estado.
     *
     * El estado NO se deduce en una consulta suelta: se deja aqui, en el
     * unico lugar que puede cambiarlo, para que "agotado" signifique siempre
     * lo mismo.
     */
    private function aplicarAlLote(LoteProducto $lote, SentidoMovimiento $sentido, float $cantidad): void
    {
        if ($lote->estado === EstadoLote::MERMADO) {
            throw ErrorDePanaderia::loteAgotado($lote->codigo);
        }

        $disponible = $sentido === SentidoMovimiento::ENTRADA
            ? $lote->cantidad_disponible + $cantidad
            : $lote->cantidad_disponible - $cantidad;

        if ($disponible < 0) {
            // Se avisa aqui, con un mensaje que dice los dos numeros, en vez
            // de dejar que reviente el gatillo con un error de PostgreSQL.
            throw ErrorDePanaderia::loteAgotado($lote->codigo);
        }

        $lote->cantidad_disponible = $disponible;

        if ($lote->estado !== EstadoLote::VENCIDO) {
            $lote->estado = $disponible <= 0
                ? EstadoLote::AGOTADO
                : EstadoLote::DISPONIBLE;
        }

        $lote->save();
    }

    /**
     * El stock de un articulo, ledido del libro de movimientos.
     *
     * POR QUE NO ES UNA COLUMNA
     * -------------------------
     * Una columna `stock_actual` puede desincronizarse del libro sin que
     * nadie lo note. Leyendo la suma de los movimientos, el saldo es por
     * definicion lo mismo que la historia, y un informe de merma que compara
     * las dos cosas siempre da igual.
     *
     * Los insumos a granel tambien tienen su saldo aca: sus movimientos no
     * van a ningun lote, pero se cuentan igual.
     */
    public function stockDeArticulo(string $articuloId): float
    {
        $saldo = $this->conexion->table('movimientos_inventario')
            ->where('articulo_id', $articuloId)
            ->selectRaw("COALESCE(SUM(CASE WHEN sentido = 'entrada' THEN cantidad ELSE -cantidad END), 0) AS saldo")
            ->value('saldo');

        return (float) $saldo;
    }

    /**
     * Los saldos de TODOS los articulos en una sola consulta.
     *
     * Es lo que usa el listado de bajo minimo. Hacerlo articulo por articulo
     * seria una consulta por cada fila de la pantalla, y en una panaderia con
     * doscientos articulos eso son doscientas consultas para pintar una tabla.
     *
     * @return \Illuminate\Support\Collection<int, array{articulo_id: string, nombre: string, sku: string, saldo: float, stock_minimo: float|null}>
     */
    public function saldosDeArticulos(): \Illuminate\Support\Collection
    {
        $saldoCrudo = $this->conexion->table('movimientos_inventario')
            ->select('articulo_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN sentido = 'entrada' THEN cantidad ELSE -cantidad END), 0) AS saldo")
            ->groupBy('articulo_id')
            ->pluck('saldo', 'articulo_id');

        $articulos = $this->conexion->table('articulos')
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'sku', 'stock_minimo']);

        return $articulos->map(fn ($a) => [
            'articulo_id' => $a->id,
            'nombre' => $a->nombre,
            'sku' => $a->sku,
            'saldo' => (float) ($saldoCrudo[$a->id] ?? 0),
            'stock_minimo' => $a->stock_minimo === null ? null : (float) $a->stock_minimo,
        ]);
    }

    /**
     * Mueve un lote de una ubicacion a otra.
     *
     * NO genera movimiento, y esa es la decision: las unidades no entraron ni
     * salieron del local, asi que contarlas seria sumar dos veces la misma
     * mercaderia. El cambio queda en el registro de auditoria.
     *
     * `ubicacion_id` no esta entre las columnas protegidas del gatillo de la
     * migracion 000005, justamente para que este traslado sea posible sin
     * falsear el libro.
     */
    public function trasladar(LoteProducto $lote, Ubicacion $destino): void
    {
        if ($lote->ubicacion_id === $destino->id) {
            return;
        }

        $lote->ubicacion_id = $destino->id;
        $lote->save();
    }
}