<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Salud del servicio.
 *
 * ES PUBLICA POR DISENO Y NO DICE NADA SENSIBLE
 * --------------------------------------------
 * Dice si el proceso responde y si la base contesta. No dice cuantos
 * articulos hay, ni que panaderias estan cargadas, ni que version corre.
 *
 * Eso ultimo parece inofensivo y no lo es: un detalle de version en un
 * endpoint publico es la forma mas facil de que alguien sepa que version sin
 * parchear esta corriendo y arme un ataque a medida. Ver docs/07.
 */
class SaludController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $base = true;

        try {
            DB::select('SELECT 1');
        } catch (\Throwable $e) {
            $base = false;
        }

        // 503 cuando la base no contesta: un chequeo de salud que dice
        // "todo bien" con la base caida hace que el orquestador no reinicie
        // el contenedor.
        return response()->json([
            'estado' => $base ? 'ok' : 'degradado',
            'base' => $base,
        ], $base ? 200 : 503);
    }
}