<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Venta;
use App\Models\Usuario;

/**
 * Ventas de mostrador.
 *
 * VENDER es de todos los que operan: el mostrador no puede esperar a que un
 * encargado autorice cada pan.
 *
 * ANULAR es de `encargado` o `administrador`, y solo aplica a ventas
 * `abiertas`. Una venta ya cobrada no se anula desde aca: se devuelve el
 * dinero y se registra el retiro. Ver `RegistradorDeVenta::anular`.
 */
class VentaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Venta $venta): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeVender();
    }

    public function cobrar(Usuario $usuario, Venta $venta): bool
    {
        return $usuario->rol->puedeVender();
    }

    public function anular(Usuario $usuario, Venta $venta): bool
    {
        return $usuario->rol->puedeAnular();
    }
}