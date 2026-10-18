<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Hacia donde fue la mercaderia.
 *
 * Con estos dos valores el saldo de un lote se calcula restando SOLO las
 * salidas: `inicial + SUMA(entradas) - SUMA(salidas)`. Si el saldo dependiera
 * de una suma con signo escondido, un movimiento mal escrito dejaria el
 * numero bien y el inventario mal.
 */
enum SentidoMovimiento: string
{
    case ENTRADA = 'entrada';
    case SALIDA = 'salida';

    public function suma(): int
    {
        return $this === self::ENTRADA ? 1 : -1;
    }
}