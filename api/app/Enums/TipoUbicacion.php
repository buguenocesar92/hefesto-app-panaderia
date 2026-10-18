<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Donde se guarda la mercaderia dentro de la panaderia.
 *
 * No es un dato de adorno: el pan del dia esta en el mostrador y el de ayer
 * vuelve a la bodega. Sin ubicaciones, la pregunta "donde quedo esta bolsa
 * de pan" no tiene respuesta y el sobrante se termina mermando por no
 * encontrarlo.
 */
enum TipoUbicacion: string
{
    case BODEGA = 'bodega';
    case MOSTRADOR = 'mostrador';
    case REFRIGERACION = 'refrigeracion';

    /** El mostrador es lo que se ofrece al publico. */
    public function esDeVenta(): bool
    {
        return $this === self::MOSTRADOR;
    }
}