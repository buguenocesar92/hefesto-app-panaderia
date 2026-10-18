<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Articulo;
use App\Models\Usuario;

/**
 * Articulos: el catalogo maestro de la panaderia.
 *
 * Un `mostrador` y un `hornero` necesitan VER el catalogo para poder cobrar y
 * producir. Crear o desactivar un articulo es decision de administracion:
 * desactivar mal un articulo que tiene pedidos abiertos deja pedidos sin
 * surtir.
 */
class ArticuloPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Articulo $articulo): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarConfiguracion();
    }

    public function update(Usuario $usuario, Articulo $articulo): bool
    {
        return $usuario->rol->puedeAdministrarConfiguracion();
    }

    /**
     * Desactivar un articulo es mas delicado que editarlo: corta la venta y
     * el consumo en receta sin avisar.
     */
    public function desactivar(Usuario $usuario, Articulo $articulo): bool
    {
        return $usuario->rol->puedeAdministrarConfiguracion();
    }

    /** Ver el stock es parte del dia a dia, para todos los que operan. */
    public function verStock(Usuario $usuario, Articulo $articulo): bool
    {
        return true;
    }

    /** Ver el libro de movimientos de un articulo, para todos. */
    public function verMovimientos(Usuario $usuario, Articulo $articulo): bool
    {
        return true;
    }
}