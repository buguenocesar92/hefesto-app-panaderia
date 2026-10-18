<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Support\Facades\Log;

/**
 * Cruza la identidad de Authentik con la persona de la panaderia.
 *
 * LA REGLA QUE DEFINE A QUE PANADERIA PERTENECE LA CUENTA
 * ------------------------------------------------------
 * Por el `slug` que la persona eligio en la pantalla de acceso.
 *
 * Ese `slug` viaja en la sesion desde que se elige hasta que vuelve el
 * callback, y es el unico dato de empresa que existe antes de autenticarse.
 * El correo de Authentik NO decide la empresa: con dominio compartido
 * ("@gmail.com") no sirve, y ademas seria adivinar.
 *
 * Que nadie tenga cuenta con ese correo significa que la
 * persona entra al sistema y ve una lista de panaderias, en vez de entrar en
 * la primera que se parezca. Eso es informacion de mas, no un problema.
 */
class VinculadorDeIdentidad
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function vincular(array $claims, ?string $slugPanaderia): Usuario
    {
        $correo = strtolower(trim((string) ($claims['email'] ?? '')));

        if ($correo === '') {
            throw new \App\Exceptions\ErrorDePanaderia(
                'El proveedor de identidad no devolvio el correo.',
                'correo_ausente'
            );
        }

        if (! empty($claims['email_verified']) && $claims['email_verified'] === false) {
            // Un correo sin verificar puede ser de cualquiera. Con una
            // panaderia que factura, no alcanza.
            throw new \App\Exceptions\ErrorDePanaderia(
                'Tu correo no esta verificado en el proveedor de identidad.',
                'correo_no_verificado'
            );
        }

        $panaderia = $this->resolverPanaderia($slugPanaderia, $correo);

        $usuario = Usuario::query()
            ->where('tenant_id', $panaderia->id)
            ->where('email', $correo)
            ->first();

        if ($usuario === null) {
            Log::info('Acceso rechazado: la cuenta no existe en esa panaderia', [
                'correo' => $correo,
                'panaderia' => $panaderia->slug,
            ]);

            throw new \App\Exceptions\ErrorDePanaderia(
                'No tienes una cuenta en esa panaderia. Pide al encargado que te cree el acceso.',
                'cuenta_no_existe'
            );
        }

        if (! $usuario->activo) {
            throw new \App\Exceptions\ErrorDePanaderia(
                'Tu cuenta esta desactivada.',
                'cuenta_desactivada'
            );
        }

        return $usuario;
    }

    /**
     * Con que panaderia se entra.
     *
     * Si la persona eligio una, esa manda. Si no eligio ninguna, se buscan
     * todas las panaderias donde ese correo tenga cuenta: si hay mas de una,
     * la eleccion tiene que ser explicita.
     */
    private function resolverPanaderia(?string $slug, string $correo): Tenant
    {
        if ($slug !== null && $slug !== '') {
            $panaderia = Tenant::buscarPorSlug($slug);

            if ($panaderia === null || ! $panaderia->activo) {
                throw new \App\Exceptions\ErrorDePanaderia(
                    'Esa panaderia no esta disponible.',
                    'panaderia_no_disponible'
                );
            }

            return $panaderia;
        }

        // Sin eleccion: se resuelve por el correo, que es unico dentro de
        // cada panaderia.
        $candidatas = Tenant::query()
            ->where('activo', true)
            ->whereHas('usuarios', fn ($q) => $q->where('email', $correo))
            ->get();

        if ($candidatas->count() === 1) {
            return $candidatas->first();
        }

        if ($candidatas->count() === 0) {
            throw new \App\Exceptions\ErrorDePanaderia(
                'Tu correo no tiene acceso a ninguna panaderia.',
                'sin_acceso'
            );
        }

        throw new \App\Exceptions\ErrorDePanaderia(
            'Tu correo tiene cuentas en varias panaderias. Elige con cual quieres entrar.',
            'varias_panaderias'
        );
    }
}