<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Como se registro una merma.
 *
 * La distincion importa en el informe: la merma automatica es el reloj acting,
 * la manual es una persona. Juntas dan la respuesta a la pregunta que una
 * panaderia se hace al cierre: "¿la perdida viene del tiempo o de las
 * manos?".
 */
enum OrigenMerma: string
{
    case MANUAL = 'manual';
    case AUTOMATICA = 'automatica';

    public function esDePersona(): bool
    {
        return $this === self::MANUAL;
    }
}