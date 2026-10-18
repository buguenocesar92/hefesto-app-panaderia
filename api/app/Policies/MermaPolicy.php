<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Merma;
use App\Models\Usuario;

/**
 * Mermas: la perdida real.
 *
 * Registrar merma es del `hornero` y del `encargado`: es parte del turno y
 * tiene que poder hacerse sin pedir permiso a nadie.
 *
 * Ver el INFORME es otra cosa. `mostrador` queda afuera a proposito: el
 * informe muestra cuantos pesos perdio la panaderia y en que. No es un dato
 * de todos.
 */
class MermaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Merma $merma): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeRegistrarMerma();
    }

    public function reporte(Usuario $usuario): bool
    {
        return $usuario->rol->puedeVerReportesDeMerma();
    }
}