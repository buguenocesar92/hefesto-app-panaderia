<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PedidoTorta;
use App\Models\Usuario;

/**
 * Pedidos de torta: el encargo que hay que agendar.
 *
 * La separacion importante esta en la agenda: `mostrador` puede VER la
 * agenda (tiene que saber si hay que dejar espacio en la vitrina), pero
 * agendar, confirmar y despachar es del `hornero` y del `encargado`.
 *
 * Anular queda en `encargado`: si quedo anticipo, hay una plata de por medio
 * y esa decision no es de quien tomo el pedido.
 */
class PedidoTortaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, PedidoTorta $pedido): bool
    {
        return true;
    }

    /** La agenda: que hay que entregar hoy. Visible para todos. */
    public function agenda(Usuario $usuario): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeProducir();
    }

    public function update(Usuario $usuario, PedidoTorta $pedido): bool
    {
        return $usuario->rol->puedeProducir();
    }

    public function confirmar(Usuario $usuario, PedidoTorta $pedido): bool
    {
        return $usuario->rol->puedeProducir();
    }

    public function anticipo(Usuario $usuario, PedidoTorta $pedido): bool
    {
        return $usuario->rol->puedeProducir();
    }

    public function despachar(Usuario $usuario, PedidoTorta $pedido): bool
    {
        return $usuario->rol->puedeProducir();
    }

    public function anular(Usuario $usuario, PedidoTorta $pedido): bool
    {
        return $usuario->rol->puedeAnular();
    }
}