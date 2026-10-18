<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tanda;
use App\Models\Usuario;

/**
 * Tandas: la produccion.
 *
 * El `hornero` registra la tanda que hizo: es su trabajo y es su
 * responsabilidad. Pero NO la anula: anular una tanda devuelve insumos al
 * inventario y deja de existir la produccion, y eso lo decide el encargado.
 */
class TandaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Tanda $tanda): bool
    {
        return true;
    }

    /** Planificar y registrar la corrida. */
    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeProducir();
    }

    /** Registrar que salio del horno: lo hace quien estuvo en el turno. */
    public function registrar(Usuario $usuario, Tanda $tanda): bool
    {
        return $usuario->rol->puedeProducir();
    }

    /** El cierre de turno y el sobrante. */
    public function sobrante(Usuario $usuario, Tanda $tanda): bool
    {
        return $usuario->rol->puedeProducir();
    }

    /**
     * Anular una tanda devuelve insumos al inventario y deja de existir la
     * produccion. Por eso no es del `hornero`.
     */
    public function anular(Usuario $usuario, Tanda $tanda): bool
    {
        return $usuario->rol->puedeAnular();
    }
}