<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoteCierre;
use App\Models\Usuario;

/**
 * Lotes de cierre: el grupo de ventas con descuento del dia.
 *
 * ABRIR uno no es privilege: es parte de la operacion normal, y el descuento
 * lo decide la panaderia cada manana segun lo que le queda.
 *
 * CERRARLO es lo que cierra la caja del dia. Solo `encargado` y
 * `administrador`, porque despues las ventas ya no pueden entrar a ese lote
 * y el arqueo tiene que poder explicarse.
 */
class LoteCierrePolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, LoteCierre $loteCierre): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeVender();
    }

    public function cerrar(Usuario $usuario, LoteCierre $loteCierre): bool
    {
        return $usuario->rol->puedeCerrarDia();
    }
}