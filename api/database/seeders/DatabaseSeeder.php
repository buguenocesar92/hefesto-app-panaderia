<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de los datos de ejemplo.
 *
 * `php artisan db:seed` corre esto.
 *
 * OJO: los datos que crea son de EJEMPLO, con precios inventados. No son
 * los de ninguna panaderia real.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PanaderiaEjemploSeeder::class);
    }
}