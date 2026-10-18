<?php

declare(strict_types=1);

use App\Http\Controllers\ArticuloController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\LoteCierreController;
use App\Http\Controllers\MermaController;
use App\Http\Controllers\PedidoTortaController;
use App\Http\Controllers\ReporteMermaController;
use App\Http\Controllers\SaludController;
use App\Http\Controllers\RecetaController;
use App\Http\Controllers\TandaController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de la panaderia
|--------------------------------------------------------------------------
|
| Las rutas viven en el grupo `web` de Laravel a proposito: la panaderia se
| opera desde una interfaz servida por Nuxt, que actua como servidor
| intermedio (BFF). Asi la cookie de sesion viaja en el mismo origen y el
| token CSRF se reenvia como cabecera `X-XSRF-TOKEN`. Ver docs/07.
|
| Lo que es publico: el acceso, el callback, la salud y el selector de
| panaderias. Nada mas. No hay endpoints de datos de negocio sin sesion.
|
*/

// --- Publico ---------------------------------------------------------------

Route::get('/api/v1/salud', SaludController::class)->name('salud');

/*
| El selector del acceso.
|
| Lee la vista `vw_empresas_publicas`, que expone SOLO tres columnas
| (slug, nombre, activo) y solo de las panaderias activas. Esta es la
| compensacion de que la tabla `tenants` no lleve Row Level Security: antes
| de autenticarse no hay `app.tenant_id` que poner, asi que la lectura previa
| se hace contra la vista y no contra la tabla.
|
| La vista se USA de verdad: este endpoint es lo que la pantalla de entrada
| muestra, y el `slug` que devuelve es el que viaja en la sesion hasta el
| callback. Ver docs/03.
*/
Route::get('/api/v1/panaderias', [AuthController::class, 'panaderias'])->name('panaderias');

Route::get('/api/v1/acceso', [AuthController::class, 'entrar'])->name('acceso');
Route::get('/api/v1/acceso/callback', [AuthController::class, 'callback'])->name('acceso.callback');

// --- Con sesion -----------------------------------------------------------

Route::middleware('auth')->group(function (): void {

    Route::post('/api/v1/salir', [AuthController::class, 'salir'])->name('salir');
    Route::get('/api/v1/yo', [AuthController::class, 'yo'])->name('yo');
    Route::get('/api/v1/yo/diagnostico', [AuthController::class, 'diagnostico'])->name('yo.diagnostico');

    // --- Articulos e insumos ---------------------------------------------
    //
    // `articulos` son a la vez lo que se vende en el mostrador y lo que se
    // consume en las recetas: la bolsa de harina que se vende al publico sale
    // del mismo inventario que la harina del pan. De ahi el nombre generico.

    Route::get('/api/v1/articulos', [ArticuloController::class, 'index'])->name('articulos.index');
    Route::post('/api/v1/articulos', [ArticuloController::class, 'store'])->name('articulos.store');
    Route::get('/api/v1/articulos/bajo-minimo', [ArticuloController::class, 'bajoMinimo'])
        ->name('articulos.bajo-minimo');

    Route::get('/api/v1/articulos/{articulo}', [ArticuloController::class, 'show'])->name('articulos.show');
    Route::put('/api/v1/articulos/{articulo}', [ArticuloController::class, 'update'])->name('articulos.update');
    Route::post('/api/v1/articulos/{articulo}/desactivar', [ArticuloController::class, 'desactivar'])
        ->name('articulos.desactivar');
    Route::get('/api/v1/articulos/{articulo}/movimientos', [ArticuloController::class, 'movimientos'])
        ->name('articulos.movimientos');

    // --- Recetas ----------------------------------------------------------

    Route::get('/api/v1/recetas', [RecetaController::class, 'index'])->name('recetas.index');
    Route::post('/api/v1/recetas', [RecetaController::class, 'store'])->name('recetas.store');

    Route::get('/api/v1/recetas/{receta}', [RecetaController::class, 'show'])->name('recetas.show');
    Route::put('/api/v1/recetas/{receta}', [RecetaController::class, 'update'])->name('recetas.update');

    // El calculo de rendimiento es la pregunta del dia a dia del hornero:
    // "si hago dos tandas de esto, cuanto harina necesito y cuanto pan sale".
    Route::get('/api/v1/recetas/{receta}/rendimiento', [RecetaController::class, 'rendimiento'])
        ->name('recetas.rendimiento');

    // --- Produccion por tanda --------------------------------------------

    Route::get('/api/v1/tandas', [TandaController::class, 'index'])->name('tandas.index');
    Route::post('/api/v1/tandas', [TandaController::class, 'store'])->name('tandas.store');
    Route::get('/api/v1/tandas/planificadas', [TandaController::class, 'planificadas'])
        ->name('tandas.planificadas');

    Route::get('/api/v1/tandas/{tanda}', [TandaController::class, 'show'])->name('tandas.show');

    // Registrar el lote es la accion que produce el pan de verdad: descuenta
    // insumos del inventario y crea el lote con su fecha de vencimiento.
    Route::post('/api/v1/tandas/{tanda}/registrar', [TandaController::class, 'registrar'])
        ->name('tandas.registrar');

    // Sobrante del turno anterior: el pan que no se vendio vuelve al
    // inventario del dia siguiente. Sin esto, la merma es invisible.
    Route::post('/api/v1/tandas/{tanda}/sobrante', [TandaController::class, 'sobrante'])
        ->name('tandas.sobrante');

    Route::post('/api/v1/tandas/{tanda}/anular', [TandaController::class, 'anular'])
        ->name('tandas.anular');

    // --- Merma ------------------------------------------------------------

    Route::get('/api/v1/mermas', [MermaController::class, 'index'])->name('mermas.index');
    Route::post('/api/v1/mermas', [MermaController::class, 'store'])->name('mermas.store');
    Route::get('/api/v1/mermas/motivos', [MermaController::class, 'motivos'])->name('mermas.motivos');

    // --- Mostrador --------------------------------------------------------

    Route::get('/api/v1/ventas', [VentaController::class, 'index'])->name('ventas.index');
    Route::post('/api/v1/ventas', [VentaController::class, 'store'])->name('ventas.store');

    Route::get('/api/v1/ventas/{venta}', [VentaController::class, 'show'])->name('ventas.show');
    Route::post('/api/v1/ventas/{venta}/pagar', [VentaController::class, 'pagar'])->name('ventas.pagar');
    Route::post('/api/v1/ventas/{venta}/anular', [VentaController::class, 'anular'])->name('ventas.anular');

    // --- Lotes de cierre --------------------------------------------------
    //
    // El descuento se aplica por lote de cierre, no por venta: es el manera
    // en que una panaderia real liquida la mercaderia del dia que se
    // acerca a su vencimiento.

    Route::get('/api/v1/lotes-cierre', [LoteCierreController::class, 'index'])->name('lotes-cierre.index');
    Route::post('/api/v1/lotes-cierre', [LoteCierreController::class, 'store'])->name('lotes-cierre.store');
    Route::get('/api/v1/lotes-cierre/{loteCierre}', [LoteCierreController::class, 'show'])
        ->name('lotes-cierre.show');
    Route::post('/api/v1/lotes-cierre/{loteCierre}/cerrar', [LoteCierreController::class, 'cerrar'])
        ->name('lotes-cierre.cerrar');

    // --- Pedidos de torta -------------------------------------------------
    //
    // Es la venta de mayor valor y la que se pierde si no se agenda.

    Route::get('/api/v1/pedidos-torta', [PedidoTortaController::class, 'index'])->name('pedidos-torta.index');
    Route::post('/api/v1/pedidos-torta', [PedidoTortaController::class, 'store'])->name('pedidos-torta.store');
    Route::get('/api/v1/pedidos-torta/agenda', [PedidoTortaController::class, 'agenda'])
        ->name('pedidos-torta.agenda');

    Route::get('/api/v1/pedidos-torta/{pedido}', [PedidoTortaController::class, 'show'])
        ->name('pedidos-torta.show');
    Route::put('/api/v1/pedidos-torta/{pedido}', [PedidoTortaController::class, 'update'])
        ->name('pedidos-torta.update');
    Route::post('/api/v1/pedidos-torta/{pedido}/confirmar', [PedidoTortaController::class, 'confirmar'])
        ->name('pedidos-torta.confirmar');
    Route::post('/api/v1/pedidos-torta/{pedido}/anticipo', [PedidoTortaController::class, 'anticipo'])
        ->name('pedidos-torta.anticipo');
    Route::post('/api/v1/pedidos-torta/{pedido}/despachar', [PedidoTortaController::class, 'despachar'])
        ->name('pedidos-torta.despachar');
    Route::post('/api/v1/pedidos-torta/{pedido}/anular', [PedidoTortaController::class, 'anular'])
        ->name('pedidos-torta.anular');

    // --- Inventario -------------------------------------------------------

    Route::get('/api/v1/inventario', [InventarioController::class, 'index'])->name('inventario.index');
    Route::get('/api/v1/inventario/lotes', [InventarioController::class, 'lotes'])->name('inventario.lotes');
    Route::get('/api/v1/inventario/lotes/{lote}', [InventarioController::class, 'lote'])
        ->name('inventario.lote');
    Route::get('/api/v1/inventario/caducando', [InventarioController::class, 'caducando'])
        ->name('inventario.caducando');
    Route::post('/api/v1/inventario/recepcion', [InventarioController::class, 'recepcion'])
        ->name('inventario.recepcion');

    // --- Reportes de merma ------------------------------------------------

    Route::get('/api/v1/reportes/merma', [ReporteMermaController::class, 'indice'])->name('reportes.merma');
    Route::get('/api/v1/reportes/merma/por-motivo', [ReporteMermaController::class, 'porMotivo'])
        ->name('reportes.merma.por-motivo');
    Route::get('/api/v1/reportes/merma/por-producto', [ReporteMermaController::class, 'porProducto'])
        ->name('reportes.merma.por-producto');

    // --- Configuracion ----------------------------------------------------
    //
    // Aqui viven las reglas que la panaderia ajusta: las vigencias de cada
    // producto, los motivos de merma y las personas con su rol.

    Route::get('/api/v1/configuracion', [ConfiguracionController::class, 'resumen'])
        ->name('configuracion.resumen');

    Route::get('/api/v1/configuracion/vigencias', [ConfiguracionController::class, 'vigencias'])
        ->name('configuracion.vigencias');
    Route::post('/api/v1/configuracion/vigencias', [ConfiguracionController::class, 'guardarVigencia'])
        ->name('configuracion.vigencias.guardar');

    Route::get('/api/v1/configuracion/motivos-merma', [ConfiguracionController::class, 'motivosMerma'])
        ->name('configuracion.motivos-merma');
    Route::post('/api/v1/configuracion/motivos-merma', [ConfiguracionController::class, 'guardarMotivoMerma'])
        ->name('configuracion.motivos-merma.guardar');

    Route::get('/api/v1/configuracion/unidades', [ConfiguracionController::class, 'unidades'])
        ->name('configuracion.unidades');
    Route::post('/api/v1/configuracion/unidades', [ConfiguracionController::class, 'guardarUnidad'])
        ->name('configuracion.unidades.guardar');

    Route::get('/api/v1/configuracion/ubicaciones', [ConfiguracionController::class, 'ubicaciones'])
        ->name('configuracion.ubicaciones');
    Route::post('/api/v1/configuracion/ubicaciones', [ConfiguracionController::class, 'guardarUbicacion'])
        ->name('configuracion.ubicaciones.guardar');

    Route::get('/api/v1/configuracion/personas', [ConfiguracionController::class, 'personas'])
        ->name('configuracion.personas');
    Route::post('/api/v1/configuracion/personas/{usuario}/rol', [ConfiguracionController::class, 'cambiarRol'])
        ->name('configuracion.personas.rol');
});