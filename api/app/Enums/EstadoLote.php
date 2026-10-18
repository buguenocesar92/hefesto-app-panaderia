<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que paso con un lote.
 *
 * `vencido` y `mermado` estan separados a proposito: un lote vencido que
 * todavia no se mermo sigue siendo mercaderia en el mostrador y hay que
 * verlo. Solo cuando la merma se registra pasa a `mermado`.
 *
 * Ver docs/06-PRIMERA-PRODUCCION.md.
 */
enum EstadoLote: string
{
    case DISPONIBLE = 'disponible';
    case AGOTADO = 'agotado';
    case VENCIDO = 'vencido';
    case MERMADO = 'mermado';

    /** El lote no se puede vender en este estado. */
    public function sePuedeVender(): bool
    {
        return $this === self::DISPONIBLE;
    }

    /** El lote no admite mas salidas: ya se vacio del todo. */
    public function estaCerrado(): bool
    {
        return in_array($this, [self::AGOTADO, self::MERMADO], true);
    }
}