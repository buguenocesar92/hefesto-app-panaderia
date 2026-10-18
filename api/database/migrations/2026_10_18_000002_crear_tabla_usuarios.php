<?php

declare(strict_types=1);

use App\Enums\RolOperador;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas que operan la panaderia.
 *
 * La identidad (contrasena, segundo factor, recuperacion) la resuelve
 * Authentik. Aqui solo se guarda lo propio de la panaderia: quien es y que
 * puede hacer. El `email` es la llave con que se cruza con Authentik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();

            // `restrictOnDelete`: borrar una panaderia con personas,
            // dandadas y ventas encima no puede ser un borrado en cascada
            // silencioso. Esa decision es de una persona, no de una clave
            // foranea.
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('email', 160);
            $tabla->string('nombre', 160);

            // El rol por omision es el mas restringido de los que operan: el
            // mostrador. Nadie nace con permisos de encargo.
            $tabla->string('rol', 32)->default(RolOperador::MOSTRADOR->value);
            $tabla->boolean('activo')->default(true);

            $tabla->timestampsTz();

            // El correo es UNICO dentro de la panaderia, no en toda la
            // instalacion: la misma persona puede trabajar en dos panaderias
            // distintas de Hefesto con el mismo correo.
            $tabla->unique(['tenant_id', 'email']);
            $tabla->index(['tenant_id', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};