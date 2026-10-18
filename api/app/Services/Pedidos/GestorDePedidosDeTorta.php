<?php

declare(strict_types=1);

namespace App\Services\Pedidos;

use App\Enums\EstadoPedidoTorta;
use App\Exceptions\ErrorDePanaderia;
use App\Models\Despacho;
use App\Models\PedidoTorta;
use App\Services\Ventas\SelectorPorVencimiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos de torta: agendar, confirmar, cobrar anticipo y despachar.
 *
 * POR QUE ESTA SEPARADO DEL MOSTRADOR
 * -----------------------------------
 * Una venta de mostrador es pan que sale ahora. Un pedido de torta es un
 * encargo con fecha: se toma hoy, se entrega en tres dias y a veces hay que
 * producirlo el dia anterior porque la torta del cliente se terminaba
 * temprano.
 *
 * El problema real es otro y no es de datos: un pedido que no esta agendado
 * no existe. Una torta perdida es la perdida mas cara del negocio, muy por
 * encima de toda la merma de pan junta.
 *
 * LA REGLA DEL ANTICIPO
 * ---------------------
 * Confirmar un pedido sin anticipo esta prohibido. Es la unica proteccion
 * real contra una torta que se produce y no se cobra: el anticipo es la
 * unica seña que existe.
 */
class GestorDePedidosDeTorta
{
    public function __construct(private readonly SelectorPorVencimiento $selector) {}

    /**
     * Agenda un pedido nuevo.
     *
     * @param  array<string, mixed>  $datos
     */
    public function agendar(array $datos): PedidoTorta
    {
        $tenantId = auth()->user()?->tenant_id;

        if ($tenantId === null) {
            throw new ErrorDePanaderia(
                'No se puede agendar un pedido sin saber a que panaderia pertenece.',
                'sin_empresa'
            );
        }

        $montoTotal = (float) ($datos['monto_total'] ?? 0);
        $anticipo = (float) ($datos['anticipo'] ?? 0);

        if ($anticipo > $montoTotal) {
            throw new ErrorDePanaderia(
                'El anticipo es mayor que el total del pedido.',
                'anticipo_mayor_que_total'
            );
        }

        $pedido = new PedidoTorta([
            'tenant_id' => $tenantId,
            'numero' => $this->numeroDePedido($tenantId),
            'cliente_nombre' => $datos['cliente_nombre'],
            'cliente_contacto' => $datos['cliente_contacto'] ?? null,
            'articulo_id' => $datos['articulo_id'],
            'cantidad' => (int) ($datos['cantidad'] ?? 1),
            'tamano' => $datos['tamano'] ?? null,
            'sabor' => $datos['sabor'] ?? null,
            'diseno' => $datos['diseno'] ?? null,
            'mensaje_personalizado' => $datos['mensaje_personalizado'] ?? null,
            'fecha_entrega' => Carbon::parse($datos['fecha_entrega'])->toDateString(),
            'hora_entrega' => $datos['hora_entrega'] ?? null,
            'estado' => EstadoPedidoTorta::BORRADOR,
            'monto_total' => $montoTotal,
            'anticipo' => $anticipo,
            'creado_por_id' => $datos['creado_por_id'] ?? auth()->id(),
            'notas' => $datos['notas'] ?? null,
        ]);

        $pedido->recalculaSaldo();
        $pedido->save();

        return $pedido->fresh();
    }

    /** Confirma el pedido. Exige anticipo. */
    public function confirmar(PedidoTorta $pedido): PedidoTorta
    {
        if ($pedido->estado !== EstadoPedidoTorta::BORRADOR) {
            throw ErrorDePanaderia::estadoInvalido($pedido->estado->value, EstadoPedidoTorta::CONFIRMADO->value);
        }

        if ($pedido->anticipo <= 0) {
            throw ErrorDePanaderia::pedidoSinAnticipo();
        }

        $pedido->estado = EstadoPedidoTorta::CONFIRMADO;
        $pedido->save();

        return $pedido->fresh();
    }

    /**
     * Suma un anticipo. Puede repetirse: se pone la mitad ahora y el resto
     * despues.
     */
    public function agregarAnticipo(PedidoTorta $pedido, float $monto): PedidoTorta
    {
        if (! $pedido->estado->tieneDinero()) {
            throw ErrorDePanaderia::estadoInvalido($pedido->estado->value, EstadoPedidoTorta::CONFIRMADO->value);
        }

        if ($monto <= 0) {
            throw new ErrorDePanaderia(
                'El anticipo tiene que ser mayor que cero.',
                'anticipo_invalido'
            );
        }

        $nuevoAnticipo = $pedido->anticipo + $monto;

        if ($nuevoAnticipo > $pedido->monto_total) {
            throw new ErrorDePanaderia(
                'Con ese anticipo el pedido queda sobrepagado.',
                'anticipo_mayor_que_total'
            );
        }

        $pedido->anticipo = $nuevoAnticipo;
        $pedido->recalculaSaldo();
        $pedido->save();

        return $pedido->fresh();
    }

    /** Marca el pedido como listo para retirar o despachar. */
    public function marcarListo(PedidoTorta $pedido): PedidoTorta
    {
        if (! in_array($pedido->estado, [EstadoPedidoTorta::CONFIRMADO, EstadoPedidoTorta::EN_PRODUCCION], true)) {
            throw ErrorDePanaderia::estadoInvalido($pedido->estado->value, EstadoPedidoTorta::LISTO->value);
        }

        $pedido->estado = EstadoPedidoTorta::LISTO;
        $pedido->save();

        return $pedido->fresh();
    }

    /**
     * Despacha el pedido: descuenta la torta del lote y lo marca entregado.
     *
     * @param  array{direccion?: string|null, entregado_a?: string|null, lote_producto_id?: string|null, usuario_id?: string|null, notas?: string|null}  $datos
     */
    public function despachar(PedidoTorta $pedido, array $datos): Despacho
    {
        if ($pedido->estado->esFinal()) {
            throw ErrorDePanaderia::estadoInvalido($pedido->estado->value, EstadoPedidoTorta::ENTREGADO->value);
        }

        if ($pedido->despacho()->exists()) {
            throw new ErrorDePanaderia(
                'Este pedido ya tiene un despacho registrado.',
                'pedido_ya_despachado'
            );
        }

        $loteId = $datos['lote_producto_id'] ?? null;

        return DB::transaction(function () use ($pedido, $datos, $loteId): Despacho {
            $despacho = Despacho::query()->create([
                'tenant_id' => $pedido->tenant_id,
                'pedido_torta_id' => $pedido->id,
                'fecha' => Carbon::today()->toDateString(),
                'hora_entrega' => $pedido->hora_entrega,
                'direccion' => $datos['direccion'] ?? null,
                'entregado_a' => $datos['entregado_a'] ?? null,
                'lote_producto_id' => $loteId,
                'recibido_por_id' => $datos['usuario_id'] ?? auth()->id(),
                'notas' => $datos['notas'] ?? null,
            ]);

            $pedido->estado = EstadoPedidoTorta::ENTREGADO;
            $pedido->cerrado_en = now();
            $pedido->save();

            return $despacho->fresh();
        });
    }

    /**
     * Anula el pedido.
     *
     * Si quedo anticipo, avisa: el dinero hay que devolverlo, y eso es una
     * gestion que la panaderia tiene que hacer, no un numero que se
     * reinventa solo.
     */
    public function anular(PedidoTorta $pedido): PedidoTorta
    {
        if ($pedido->estado->esFinal()) {
            throw ErrorDePanaderia::estadoInvalido($pedido->estado->value, EstadoPedidoTorta::ANULADO->value);
        }

        if ($pedido->anticipo > 0) {
            throw new ErrorDePanaderia(
                "Este pedido tiene un anticipo de {$pedido->anticipo}. Devuelvelo antes de anularlo.",
                'pedido_con_anticipo'
            );
        }

        $pedido->estado = EstadoPedidoTorta::ANULADO;
        $pedido->cerrado_en = now();
        $pedido->saldo = 0;
        $pedido->save();

        return $pedido->fresh();
    }

    /**
     * La agenda: que hay que entregar hoy, manana y esta semana.
     *
     * @return array{hoy: array<int, mixed>, proximos: array<int, mixed>, atrasados: array<int, mixed>}
     */
    public function agenda(Carbon $hoy): array
    {
        $base = PedidoTorta::query()
            ->with(['articulo'])
            ->whereNotIn('estado', [
                EstadoPedidoTorta::ENTREGADO->value,
                EstadoPedidoTorta::ANULADO->value,
            ])
            ->orderBy('fecha_entrega')
            ->orderBy('hora_entrega');

        $todos = $base->get();

        $hoyLista = [];
        $proximos = [];
        $atrasados = [];

        foreach ($todos as $pedido) {
            /** @var PedidoTorta $pedido */
            $fila = [
                'id' => $pedido->id,
                'numero' => $pedido->numero,
                'cliente_nombre' => $pedido->cliente_nombre,
                'articulo' => $pedido->articulo?->nombre,
                'cantidad' => $pedido->cantidad,
                'tamano' => $pedido->tamano,
                'hora_entrega' => $pedido->hora_entrega,
                'estado' => $pedido->estado->value,
                'saldo' => (float) $pedido->saldo,
                'atrasado' => $pedido->estaAtrasado($hoy),
            ];

            if ($pedido->fecha_entrega->lessThan($hoy)) {
                // Un pedido atrasado NO se marca "perdido": sigue vigente y
                // aparece en la agenda. Marcarlo perdido solo seria inventar
                // un hecho que todavia no se sabe.
                $atrasados[] = $fila;
            } elseif ($pedido->fecha_entrega->isSameDay($hoy)) {
                $hoyLista[] = $fila;
            } else {
                $proximos[] = $fila;
            }
        }

        return [
            'hoy' => $hoyLista,
            'proximos' => $proximos,
            'atrasados' => $atrasados,
        ];
    }

    private function numeroDePedido(string $tenantId): string
    {
        $prefijo = 'T-' . Carbon::today()->format('Ymd') . '-';

        $ultimo = PedidoTorta::query()
            ->where('tenant_id', $tenantId)
            ->where('numero', 'like', $prefijo . '%')
            ->orderByDesc('numero')
            ->value('numero');

        $consecutivo = $ultimo === null ? 1 : ((int) substr($ultimo, strlen($prefijo))) + 1;

        return $prefijo . str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT);
    }
}