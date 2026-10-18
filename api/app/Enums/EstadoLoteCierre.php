<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un lote de cierre.
 *
 * Es el grupo de ventas que comparten descuento. Cuando queda cerrado, la
 * caja del dia esta cuadrada y las ventas ya no pueden entrar a el: una
 * venta cobrada con el descuento de ayer, cerrada hoy, hace el arqueo
 * imposible de entender.
 */
enum EstadoLoteCierre: string
{
    case ABIERTO = 'abierto';
    case CERRADO = 'cerrado';

    public function admiteVentas(): bool
    {
        return $this === self::ABIERTO;
    }
}