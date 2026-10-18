<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Receta;
use App\Models\Usuario;

/**
 * Recetas: como se hace y quanto rinde.
 *
 * Ver una receta es del dia a dia del horno, para todos. CAMBIAR una receta
 * no lo es: una receta mal cargada hace que toda la produccion de la semana
 * salga mal, y el costo ya esta Comprometido.
 *
 * Por eso editar exige `encargado` o `administrador`, no `horno`.
 */
class RecetaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Receta $receta): bool
    {
        return true;
    }

    /** El calculo de rendimiento es la pregunta diaria del hornero. */
    public function rendimiento(Usuario $usuario, Receta $receta): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeCerrarDia();
    }

    public function update(Usuario $usuario, Receta $receta): bool
    {
        return $usuario->rol->puedeCerrarDia();
    }

    /**
     * Cambiar una receta no la borra: se crea la version siguiente.
     *
     * Por eso esto no es un metodo de autorizacion aparte sino la misma
     * regla de `update`. Esta aqui para dejar la intencion escrita.
     */
    public function versionar(Usuario $usuario, Receta $receta): bool
    {
        return $usuario->rol->puedeCerrarDia();
    }
}