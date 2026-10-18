<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que paso con una tanda.
 *
 * El recorrido normal es `planificada` -> `en_curso` -> `terminada`.
 *
 * `anulada` es distinto de `terminada`: una tanda terminada produjo pan y sus
 * movimientos existen; una anulada nunca llego a tocar el inventario. Por eso
 * anular no borra nada, cambia el estado.
 */
enum EstadoTanda: string
{
    case PLANIFICADA = 'planificada';
    case EN_CURSO = 'en_curso';
    case TERMINADA = 'terminada';
    case ANULADA = 'anulada';

    public function estaCerrada(): bool
    {
        return in_array($this, [self::TERMINADA, self::ANULADA], true);
    }

    public function admitenSobrante(): bool
    {
        return in_array($this, [self::EN_CURSO, self::TERMINADA], true);
    }
}