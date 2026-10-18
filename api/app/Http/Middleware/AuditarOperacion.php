<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja rastro de las operaciones que cambian datos.
 *
 * Solo se registran las que mueven historia: ventas, mermas, tandas,
 * despachos y cambios de rol. Un listado no deja rastro porque no cambia
 * nada; si lo dejara, el registro seria tan largo que nadie lo leeria, y
 * un registro que nadie lee es lo mismo que no tener registro.
 *
 * Lo que se guarda NO incluye el cuerpo de la peticion: podria traer datos
 * personales de clientes.
 */
class AuditarOperacion
{
    private const RUTAS_AUDITADAS = [
        'api/v1/tandas/*',
        'api/v1/mermas',
        'api/v1/ventas',
        'api/v1/lotes-cierre/*',
        'api/v1/pedidos-torta/*',
        'api/v1/inventario/*',
        'api/v1/configuracion/personas/*',
    ];

    private const METODOS_QUE_CAMBIAN_DATOS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        $usuario = auth()->guard('web')->user();

        if ($usuario === null || ! in_array($request->method(), self::METODOS_QUE_CAMBIAN_DATOS, true)) {
            return $respuesta;
        }

        foreach (self::RUTAS_AUDITADAS as $patron) {
            if ($request->is($patron)) {
                Log::info('operacion de la panaderia', [
                    'persona' => $usuario->email,
                    'panaderia' => $usuario->tenant_id,
                    'metodo' => $request->method(),
                    'ruta' => $request->path(),
                    'respuesta' => $respuesta->getStatusCode(),
                ]);

                break;
            }
        }

        return $respuesta;
    }
}