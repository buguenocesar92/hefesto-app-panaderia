<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Controlador base.
 *
 * Las dos traits que Laravel ya no pone solo desde la version 11. Se
 * agregan a mano para que `$this->authorize(...)` funcione.
 *
 * Cuando una Policy rechaza, la respuesta NO es 403 sino 404. Se configura
 * en `bootstrap/app.php`. La razon: un 403 confirma que el recurso existe, y
 * eso ya es filtrar informacion de la base de otra panaderia.
 */
abstract class Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;
}