<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RolOperador;
use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea una persona con su rol.
 *
 * LA PITERA DE ESTE COMANDO
 * ------------------------
 * Crear la fila en `usuarios` NO es dar acceso a la panaderia: la persona
 * recien creada todavia no existe en Authentik, y sin ahi el acceso se
 * rechaza. Por eso el comando AVISA eso al final, en vez de terminar
 * diciendo "listo".
 *
 * Es un comando de instalacion, no de operacion diaria.
 */
class PanaderiaCrearPersona extends Command
{
    protected $signature = 'panaderia:crear-persona
        {--empresa= : Slug de la panaderia. Si se omite, se muestra la lista.}
        {--email= : Correo electronico. Es la llave con que se cruza con Authentik.}
        {--nombre= : Nombre visible.}
        {--rol=mostrador : Rol: auditor, mostrador, horno, encargado o administrador.}';

    protected $description = 'Crea una persona de una panaderia con su rol.';

    public function handle(): int
    {
        $slug = (string) $this->option('empresa');

        if ($slug === '') {
            $this->line('Panaderias disponibles:');

            // Se lee la vista publica: son las unicas tres columnas que se
            // pueden mostrar sin sesion.
            foreach (Tenant::publicasActivas() as $fila) {
                $this->line("  {$fila->slug}  {$fila->nombre}");
            }

            return self::SUCCESS;
        }

        $email = strtolower(trim((string) $this->option('email')));
        $nombre = trim((string) $this->option('nombre'));

        if ($email === '' || $nombre === '') {
            $this->error('Falta --email o --nombre.');

            return self::FAILURE;
        }

        $rol = RolOperador::tryFrom((string) $this->option('rol'));

        if ($rol === null) {
            $this->error('El rol no existe. Vale: '
                . implode(', ', array_map(fn (RolOperador $r) => $r->value, RolOperador::cases())) . '.');

            return self::FAILURE;
        }

        $panaderia = Tenant::buscarPorSlug($slug);

        if ($panaderia === null || ! $panaderia->activo) {
            $this->error('Esa panaderia no existe o esta inactiva.');

            return self::FAILURE;
        }

        $existe = Usuario::query()
            ->where('tenant_id', $panaderia->id)
            ->where('email', $email)
            ->exists();

        if ($existe) {
            $this->error("Esa persona ya existe en {$panaderia->nombre} con ese correo.");

            return self::FAILURE;
        }

        // Se escribe sin pasar por la sesion: este comando corre por consola y
        // no hay `app.tenant_id` que poner. Por eso se usa la conexion
        // directa y no el modelo, que pasaria por la RLS.
        DB::table('usuarios')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $panaderia->id,
            'email' => $email,
            'nombre' => $nombre,
            'rol' => $rol->value,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("Persona creada en {$panaderia->nombre}: {$nombre} <{$email}> ({$rol->value}).");
        $this->line('');
        $this->warn('Falta un paso: la persona tiene que existir tambien en Authentik,');
        $this->warn('con este mismo correo y en el grupo de operadores. Si no esta ahi,');
        $this->warn('el acceso se va a rechazar aunque la fila exista en la base.');

        return self::SUCCESS;
    }
}