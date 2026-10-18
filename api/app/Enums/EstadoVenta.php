<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una venta de mostrador.
 *
 * Una venta abierta es una caja descuadrada: el pan ya salio del almacen
 * pero el dinero no entra todavia. Por eso el mostrador no puede dejar una
 * venta abierta indefinidamente y `VentaController` revisa la hora de
 * apertura.
 */
enum EstadoVenta: string
{
    case ABIERTA = 'abierta';
    case CERRADA = 'cerrada';
    case ANULADA = 'anulada';

    public function estaCerrada(): bool
    {
        return $this !== self::ABIERTA;
    }
}