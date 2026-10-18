<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;

/**
 * Configuracion: vigencias, motivos de merma, unidades, ubicaciones y
 * personas.
 *
 * Todo lo de aca es del `administrador` y nada mas.
 *
 * La razon de que las VIGENCIAS esten tan cerradas no es desconfianza: es que
 * la vigencia de un producto es la regla que decide si algo se puede vender o
 * ya es perdida. Si cualquiera pudiera cambiarla, la caducidad dejaria de
 * ser una garantia.
 */
class ConfiguracionPolicy
{
    public function ver(Usuario $usuario): bool
    {
        return true;
    }

    public function guardarVigencia(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarConfiguracion();
    }

    public function guardarMotivoMerma(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarConfiguracion();
    }

    public function guardarUnidad(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarConfiguracion();
    }

    public function guardarUbicacion(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarConfiguracion();
    }

    public function verPersonas(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarPersonas();
    }

    /**
     * Cambiar el rol de alguien.
     *
     * Dos controles, y los dos importan:
     *
     *  1. Solo `administrador`.
     *  2. Nadie se cambia el rol a si mismo. Sin esto, el primer
     *     `administrador` de la panaderia podria degradarse y dejar el
     *     sistema sin nadie que administre, sin nadie que lo decidiera y sin
     *     forma de volver atras.
     */
    public function cambiarRol(Usuario $usuario, Usuario $objetivo): bool
    {
        if (! $usuario->rol->puedeAdministrarPersonas()) {
            return false;
        }

        return $usuario->id !== $objetivo->id;
    }
}