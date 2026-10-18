<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Medios de pago del mostrador.
 *
 * Es una lista de TEXTO, no una tabla, a proposito: la panaderia puede
 * agregar "tranferencia" o "vale" sin pedir un desarrollo. El enum obliga a
 * que el codigo sea el mismo en la venta y en el informe, pero no cierra la
 * puerta a agregar uno nuevo despues.
 */
enum MetodoPago: string
{
    case EFECTIVO = 'efectivo';
    case DEBITO = 'debito';
    case CREDITO = 'credito';
    case TRANSFERENCIA = 'transferencia';

    /** Solo el efectivo necesita vuelto. */
    public function necesitaVuelto(): bool
    {
        return $this === self::EFECTIVO;
    }
}