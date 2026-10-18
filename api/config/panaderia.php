<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Identidad de la aplicacion
    |--------------------------------------------------------------------------
    |
    | `web_url` es la direccion de la interfaz (Nuxt). Vive ACA y no en
    | `config/app.php` a proposito: el esqueleto de Laravel no trae esa clave
    | y leer `config('app.web_url')` devolveria NULL sin avisar. Ver el
    | AuthController.
    |
    */

    'web_url' => rtrim((string) env('APP_WEB_URL', 'http://localhost:3000'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Caducidad
    |--------------------------------------------------------------------------
    |
    | La vigencia NO se inventa. Cada producto tiene su propia regla en la
    | tabla `vigencias`, que es lo que una panaderia puede cambiar por
    | producto (el pan caduca hoy, el sándwich manana).
    |
    | `dias_por_defecto` es NULO por omision, y esa es la decision
    | importante: si un producto no tiene regla, el lote se produce SIN fecha
    | de vencimiento en vez de inventarle un plazo. Un lote sin vencimiento no
    | caduca solo; queda a la vista, esperando que alguien configure la regla.
    |
    | `dias_aviso` es solo la ventana de aviso de la interfaz. No bloquea
    | nada: es una ayuda, no una regla de negocio.
    |
    */

    'caducidad' => [
        'dias_por_defecto' => env('PANADERIA_VIGENCIA_POR_DEFECTO_DIAS') === null
            || env('PANADERIA_VIGENCIA_POR_DEFECTO_DIAS') === ''
                ? null
                : (int) env('PANADERIA_VIGENCIA_POR_DEFECTO_DIAS'),

        'dias_aviso' => (int) env('PANADERIA_DIAS_AVISO_CADUCIDAD', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recetas
    |--------------------------------------------------------------------------
    |
    | La merma estimada de un insumo dentro de una receta es un dato que
    | declara quien hace la panaderia. El valor por omision es 0 porque 0 es
    | un hecho (no se declaro merma) y cualquier otro numero seria una regla
    | inventada.
    |
    | `porcentaje_maximo` es un tope de plausibilidad: si alguien escribe
    | 400 % de merma en una receta, casi seguro fue un error de tipeo. No
    | bloquea el guardado; el servicio de recetas lo avisa.
    |
    */

    'recetas' => [
        'merma_por_defecto_porcentaje' => (int) env('PANADERIA_MERMA_POR_DEFECTO_PORCENTAJE', 0),

        'porcentaje_merma_maximo' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mostrador
    |--------------------------------------------------------------------------
    |
    | Una venta de mostrador se cierra sola pasado este tiempo: si el cajero
    | abre una venta y se va sin cerrarla, la caja queda descuadrada. El valor
    | por omision son 10 minutos, que es la duracion tipica de una fila de
    | dos personas. **Pendiente de confirmar con la panaderia.**
    |
    */

    'mostrador' => [
        'minutos_maximos' => (int) env('PANADERIA_MINUTOS_MAXIMOS_VENTA', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Migraciones al arrancar
    |--------------------------------------------------------------------------
    |
    | En produccion tiene que ser "false". Un despliegue que cambia el
    | esquema sin que nadie lo decida es un despliegue que puede cortar el
    | servicio a mitad de la operacion de una panaderia.
    |
    */

    'correr_migraciones' => (bool) env('PANADERIA_CORRER_MIGRACIONES', false),

];