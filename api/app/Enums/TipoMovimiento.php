<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que paso con el inventario.
 *
 * `TIPO` dice QUE OCURRIO y `SENTIDO` hacia donde fue. Son dos campos y no
 * uno porque el ajuste va en los dos sentidos: un ajuste por rotura es
 * una salida, un ajuste por correccion de conteo puede ser entrada o salida.
 *
 * La cantidad siempre es POSITIVA. El signo lo pone el sentido. Un signo
 * escondido en la cantidad es la forma clasica de que un informe de merma
 * cuadre al reves.
 */
enum TipoMovimiento: string
{
    /** Llego mercaderia de un proveedor. */
    case INGRESO = 'ingreso';

    /** Salio mercaderia: venta, consumo de receta, despacho o merma. */
    case SALIDA = 'salida';

    /** Correccion de un conteo. Es el unico caso dudoso, y por eso existe. */
    case AJUSTE = 'ajuste';

    public function sentidoPorDefecto(): SentidoMovimiento
    {
        return match ($this) {
            self::INGRESO => SentidoMovimiento::ENTRADA,
            self::SALIDA => SentidoMovimiento::SALIDA,
            self::AJUSTE => SentidoMovimiento::ENTRADA,
        };
    }
}