<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un pedido de torta.
 *
 * LA REGLA DE LAS MONEDAS
 * ----------------------
 * `BORRADOR` y `ANULADO` son los dos unicos estados sin dinero: o no se
 * confirmo, o se devolvio todo. En cualquier otro estado queda al menos un
 * anticipo, y por eso `PedidoDeTorta` no deja anular un pedido con saldo a
 * favor sin registrar antes la devolucion.
 *
 * El estado NO se deduce de la fecha. Un pedido que paso la fecha de entrega
 * sin despacharse NO se marca "perdido" solo: sigue `confirmado` y aparece en
 * la agenda como atrasado. Marcarlo como perdido automaticamente seria
 * inventar un hecho.
 */
enum EstadoPedidoTorta: string
{
    case BORRADOR = 'borrador';
    case CONFIRMADO = 'confirmado';
    case EN_PRODUCCION = 'en_produccion';
    case LISTO = 'listo';
    case ENTREGADO = 'entregado';
    case ANULADO = 'anulado';

    /** Estado final: ya no se toca. */
    public function esFinal(): bool
    {
        return in_array($this, [self::ENTREGADO, self::ANULADO], true);
    }

    /** El pedido quedo comprometido con plata de verdad. */
    public function tieneDinero(): bool
    {
        return ! in_array($this, [self::BORRADOR, self::ANULADO], true);
    }

    /** Todavia se puede editar el diseno o la fecha. */
    public function admiteCambios(): bool
    {
        return in_array($this, [self::BORRADOR, self::CONFIRMADO, self::EN_PRODUCCION], true);
    }
}