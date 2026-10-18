<?php

declare(strict_types=1);

use App\Console\Commands\PanaderiaCrearPersona;
use App\Console\Commands\PanaderiaMermarVencidos;
use App\Console\Commands\PanaderiaRevisarCaducidad;
use App\Console\Commands\PanaderiaVerificarAislamiento;
use App\Jobs\MermarVencidosJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| La caducidad no puede depender de que alguien se acuerde. El pan que paso
| de fecha y sigue en el mostrador es merma que nadie registro, y eso es
| justamente lo que el sistema existe para que no pase.
|
| `withoutOverlapping()` evita que dos pasadas se pisen: si la revision se
| demora, la segunda espera en vez de mermar dos veces el mismo lote.
|
*/

Schedule::command(PanaderiaRevisarCaducidad::class)
    ->dailyAt('07:00')
    ->name('panaderia:revisa-caducidad')
    ->withoutOverlapping();

Schedule::command(PanaderiaMermarVencidos::class)
    ->hourly()
    ->name('panaderia:merma-vencidos')
    ->withoutOverlapping();

/*
| El trabajo diferido hace lo mismo que el comando, pero sobrevive a que el
| contenedor se reinicie a mitad. Se despacha desde el comando horario para
| que quede la traza en la cola y no solo en la consola.
*/
Schedule::job(new MermarVencidosJob())
    ->hourlyAt(2)
    ->name('panaderia:merma-vencidos-programado')
    ->withoutOverlapping();

/*
| La verificacion del aislamiento corre en cada despliegue. Si falla, el
| despliegue se detiene: es la ultima linea entre una panaderia y los datos
| de otra.
*/
Schedule::command(PanaderiaVerificarAislamiento::class)
    ->hourly()
    ->name('panaderia:verifica-aislamiento');