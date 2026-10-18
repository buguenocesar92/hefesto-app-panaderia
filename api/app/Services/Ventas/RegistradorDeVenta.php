<?php

declare(strict_types=1);

namespace App\Services\Ventas;

use App\Enums\EstadoLoteCierre;
use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Enums\TipoMovimiento;
use App\Exceptions\ErrorDePanaderia;
use App\Models\Articulo;
use App\Models\LoteCierre;
use App\Models\Pago;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Inventario\RegistroDeMovimiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Venta de mostrador: abrir, cobrar y anular.
 *
 * POR QUE UNA VENTA SE ABRE ANTES DE COBRAR
 * ----------------------------------------
 * Porque el pan sale del mostrador antes de que el cliente pague, y el
 * sistema tiene que reflectir que esas unidades ya no estan disponibles. Si
 * el cobro fallara despues, el lote quedaria con stock que en realidad no
 * esta.
 *
 * Por eso el descuento del lote de cierre se aplica Y SE COPIA en cada linea.
 * Si el lote de cierre se cerrara despues con otro descuento, la venta de
 * ayer tendria que cuadrar contra el precio de ayer.
 */
class RegistradorDeVenta
{
    public function __construct(
        private readonly SelectorPorVencimiento $selector,
        private readonly RegistroDeMovimiento $movimientos,
    ) {}

    /**
     * Abre una venta con sus lineas y descuenta el stock de los lotes.
     *
     * @param  array{
     *     items: list<array{articulo_id: string, cantidad: float}>,
     *     lote_cierre_id?: string|null,
     *     usuario_id: string,
     *     notas?: string|null
     * }  $datos
     */
    public function abrir(array $datos): Venta
    {
        if (empty($datos['items'])) {
            throw new ErrorDePanaderia(
                'Una venta tiene que tener al menos un producto.',
                'venta_sin_items'
            );
        }

        $loteCierre = null;

        if (! empty($datos['lote_cierre_id'])) {
            $loteCierre = LoteCierre::query()->findOrFail($datos['lote_cierre_id']);

            if ($loteCierre->estado !== EstadoLoteCierre::ABIERTO) {
                throw ErrorDePanaderia::estadoInvalido(
                    $loteCierre->estado->value,
                    EstadoLoteCierre::ABIERTO->value
                );
            }
        }

        $tenantId = auth()->user()?->tenant_id;

        if ($tenantId === null) {
            throw new ErrorDePanaderia(
                'No se puede abrir una venta sin saber a que panaderia pertenece.',
                'sin_empresa'
            );
        }

        return DB::transaction(function () use ($datos, $loteCierre, $tenantId): Venta {
            $venta = Venta::query()->create([
                'tenant_id' => $tenantId,
                'numero' => $this->numeroDeVenta($tenantId),
                'lote_cierre_id' => $loteCierre?->id,
                'fecha' => Carbon::today()->toDateString(),
                'estado' => EstadoVenta::ABIERTA,
                'bruto' => 0,
                'descuento' => 0,
                'total' => 0,
                'abierta_en' => now(),
                'vendedor_id' => $datos['usuario_id'],
                'notas' => $datos['notas'] ?? null,
            ]);

            $bruto = 0.0;
            $descuento = 0.0;

            foreach ($datos['items'] as $item) {
                $articulo = Articulo::query()->findOrFail($item['articulo_id']);

                if (! $articulo->se_vende) {
                    throw new ErrorDePanaderia(
                        "El articulo {$articulo->nombre} no esta habilitado para el mostrador.",
                        'articulo_no_vendible'
                    );
                }

                if (! $articulo->tienePrecio()) {
                    // Un articulo sin precio no se puede cobrar. Se dice
                    // ANTES de sacar el pan del almacen.
                    throw ErrorDePanaderia::sinPrecio($articulo->nombre);
                }

                $cantidad = (float) $item['cantidad'];
                $reparto = $this->selector->repartir($articulo, $cantidad);

                if ($reparto['faltan'] > 0) {
                    throw new ErrorDePanaderia(
                        "No hay stock suficiente de {$articulo->nombre}. Faltan {$reparto['faltan']}.",
                        'sin_stock'
                    );
                }

                $precioLista = (float) $articulo->precio_venta;

                foreach ($reparto['lotes'] as $parte) {
                    $lote = $parte['lote'];
                    $cantidadParte = $parte['cantidad'];

                    $precioUnitario = $loteCierre !== null
                        ? $loteCierre->precioConDescuento($precioLista)
                        : $precioLista;

                    $subtotal = round($precioUnitario * $cantidadParte, 2);

                    VentaItem::query()->create([
                        'tenant_id' => $tenantId,
                        'venta_id' => $venta->id,
                        'articulo_id' => $articulo->id,
                        // De que lote salio. Sin esto no se sabe cuando
                        // caduca lo que queda.
                        'lote_producto_id' => $lote->id,
                        'cantidad' => $cantidadParte,
                        'precio_unitario' => $precioUnitario,
                        'descuento_unitario' => round($precioLista - $precioUnitario, 2),
                        'subtotal' => $subtotal,
                    ]);

                    // El stock se descuenta al ABRIR, no al cobrar: el pan ya
                    // salio del mostrador aunque el pago este pendiente.
                    $this->movimientos->salir([
                        'tenant_id' => $tenantId,
                        'articulo_id' => $articulo->id,
                        'lote_producto_id' => $lote->id,
                        'cantidad' => $cantidadParte,
                        'tipo' => TipoMovimiento::SALIDA,
                        'referencia' => $venta->numero,
                        'usuario_id' => $datos['usuario_id'],
                        'notas' => "Venta de mostrador {$venta->numero}.",
                    ]);

                    $bruto += $precioLista * $cantidadParte;
                    $descuento += ($precioLista - $precioUnitario) * $cantidadParte;
                }
            }

            $venta->bruto = round($bruto, 2);
            $venta->descuento = round($descuento, 2);
            $venta->total = round($bruto - $descuento, 2);
            $venta->save();

            return $venta->fresh(['items', 'pagos']);
        });
    }

    /**
     * Cobra y cierra la venta.
     *
     * @param  array{metodo: MetodoPago, monto?: float|null, recibido?: float|null, vuelto?: float|null}  $datos
     */
    public function cobrar(Venta $venta, array $datos): Venta
    {
        if ($venta->estado !== EstadoVenta::ABIERTA) {
            throw ErrorDePanaderia::estadoInvalido($venta->estado->value, EstadoVenta::CERRADA->value);
        }

        $minutos = $venta->minutosAbierta();
        $maximo = (int) config('panaderia.mostrador.minutos_maximos');

        if ($minutos > $maximo) {
            throw ErrorDePanaderia::ventaAbiertaDemasiadoTiempo((string) $minutos);
        }

        $monto = isset($datos['monto']) ? (float) $datos['monto'] : (float) $venta->total;

        if ($monto <= 0) {
            throw new ErrorDePanaderia(
                'El monto del pago tiene que ser mayor que cero.',
                'monto_invalido'
            );
        }

        $vuelto = null;

        if ($datos['metodo']->necesitaVuelto()) {
            $recibido = isset($datos['recibido']) ? (float) $datos['recibido'] : $monto;

            if ($recibido < $monto) {
                throw new ErrorDePanaderia(
                    'El efectivo recibido es menor que el total a cobrar.',
                    'efectivo_insuficiente'
                );
            }

            $vuelto = round($recibido - $monto, 2);
        }

        return DB::transaction(function () use ($venta, $datos, $monto, $vuelto): Venta {
            Pago::query()->create([
                'tenant_id' => $venta->tenant_id,
                'venta_id' => $venta->id,
                'metodo' => $datos['metodo'],
                'monto' => $monto,
                'recibido' => $datos['metodo']->necesitaVuelto() ? ($datos['recibido'] ?? $monto) : null,
                'vuelto' => $vuelto,
            ]);

            $venta->estado = EstadoVenta::CERRADA;
            $venta->cerrada_en = now();
            $venta->save();

            return $venta->fresh(['items', 'pagos']);
        });
    }

    /**
     * Anula la venta y devuelve la mercaderia a los lotes de donde salio.
     *
     * Devolver es obligatorio: si una venta se anula porque se cobro mal y el
     * pan no volviera al almacen, el inventario perderia mercaderia que en
     * realidad esta en la vitrina.
     */
    public function anular(Venta $venta): Venta
    {
        if ($venta->estado === EstadoVenta::CERRADA) {
            // Una venta cobrada no se anula: se devuelve el dinero y se
            // registra el retiro. Dejar abierto este camino permitiria
            // borrar del historico una venta que si entrego mercaderia.
            throw ErrorDePanaderia::estadoInvalido(
                $venta->estado->value,
                EstadoVenta::ANULADA->value
            );
        }

        if ($venta->estado === EstadoVenta::ANULADA) {
            return $venta;
        }

        return DB::transaction(function () use ($venta): Venta {
            foreach ($venta->items()->with('lote')->get() as $item) {
                /** @var VentaItem $item */
                if ($item->lote === null) {
                    continue;
                }

                $this->movimientos->entrar(
                    [
                        'tenant_id' => $venta->tenant_id,
                        'articulo_id' => $item->articulo_id,
                        'cantidad' => (float) $item->cantidad,
                        'ubicacion_id' => $item->lote->ubicacion_id,
                        'tipo' => TipoMovimiento::INGRESO,
                        'referencia' => $venta->numero,
                        'usuario_id' => auth()->id(),
                        'notas' => "Anulacion de la venta {$venta->numero}: vuelve al lote {$item->lote->codigo}.",
                    ],
                    $item->lote
                );
            }

            $venta->estado = EstadoVenta::ANULADA;
            $venta->cerrada_en = now();
            $venta->save();

            return $venta->fresh(['items', 'pagos']);
        });
    }

    /**
     * El correlativo de venta del dia: `V-<AAAAMMDD>-<consecutivo>`.
     *
     * Va con la fecha porque el numero se muestra en el papel de la
     * boleta y en el cuaderno del cajero, donde dos ventas con el mismo
     * numero en dias distintos son un lio para reconciliar.
     */
    private function numeroDeVenta(string $tenantId): string
    {
        $prefijo = 'V-' . Carbon::today()->format('Ymd') . '-';

        $ultimo = Venta::query()
            ->where('tenant_id', $tenantId)
            ->where('numero', 'like', $prefijo . '%')
            ->orderByDesc('numero')
            ->value('numero');

        $consecutivo = $ultimo === null ? 1 : ((int) substr($ultimo, strlen($prefijo))) + 1;

        return $prefijo . str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT);
    }
}