<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas del negocio que la panaderia ajusta, y las recetas.
 *
 * VIGENCIAS: POR QUE ES UNA TABLA Y NO UN NUMERO EN EL ARTICULO
 * -----------------------------------------------------------
 * "El pan caduca hoy, el sándwich mañana" no es una regla general: es una
 * regla POR PRODUCTO, y es la panaderia quien la fija. Poner un numero en el
 * articulo obligaria a reescribir el catalogo cada vez que cambian el pan
 * o el relleno, y no permitiria dejar un producto sin regla.
 *
 * `dias_vigencia` es NULL cuando el producto no caduca. Si no hay fila, o la
 * fila tiene `dias_vigencia` NULL, la tanda produce el lote SIN fecha de
 * vencimiento: es preferible un hueco visible a un plazo inventado. Ver
 * docs/05-CONFIGURACION.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vigencias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            // Una sola regla por producto y panaderia.
            $tabla->foreignUuid('articulo_id')->constrained('articulos')->cascadeOnDelete();

            // NULL = el producto no caduca. Un 0 significaria "caduca hoy",
            // que es una regla distinta y muy rara: por eso se usa NULL y no
            // un cero.
            $tabla->unsignedInteger('dias_vigencia')->nullable();

            // Si esta activo, el trabajo programado `panaderia:merma-vencidos`
            // puede registrar la merma solo cuando el lote vence. Con
            // `false`, el lote vencido queda a la vista y espera a que una
            // persona decida.
            $tabla->boolean('merma_automatica')->default(false);

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'articulo_id']);
        });

        Schema::create('motivos_merma', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('codigo', 32);
            $tabla->string('nombre', 120);
            $tabla->text('descripcion')->nullable();

            // `merma_por_vencimiento` separa las dos cosas que la gente suele
            // llamar "merma" y que en el informe significan distinto: lo que
            // se perdio por el tiempo y lo que se perdio por un error. Sin
            // este flag no se puede saber si la perdida viene del reloj o de
            // las manos.
            $tabla->boolean('merma_por_vencimiento')->default(false);

            $tabla->boolean('activo')->default(true);

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
        });

        Schema::create('recetas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            // El producto que produce esta receta. Un mismo producto puede
            // tener varias recetas EN DISTINTA VERSION: cambiar la receta no
            // reescribe la historia de las tandas viejas.
            $tabla->foreignUuid('articulo_id')->constrained('articulos')->restrictOnDelete();

            $tabla->string('nombre', 160);
            $tabla->unsignedInteger('version')->default(1);

            // El rendimiento es el corazon del sistema: "esta receta rinde
            // 24 unidades". De ahi sale el calculo de insumos y el
            // vencimiento del lote.
            $tabla->decimal('rendimiento_cantidad', 14, 6);
            $tabla->foreignUuid('rendimiento_unidad_id')->constrained('unidades_medida')->restrictOnDelete();

            $tabla->text('notas')->nullable();
            $tabla->boolean('activa')->default(true);

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'articulo_id', 'version']);
            $tabla->index(['tenant_id', 'activa']);
        });

        Schema::create('receta_ingredientes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('receta_id')->constrained('recetas')->restrictOnDelete();

            // El insumo apunta a `articulos`: es el mismo inventario que el
            // del mostrador. Ver la migracion 000003.
            $tabla->foreignUuid('insumo_id')->constrained('articulos')->restrictOnDelete();

            $tabla->decimal('cantidad', 14, 6);
            $tabla->foreignUuid('unidad_id')->constrained('unidades_medida')->restrictOnDelete();

            // Merma estimada al usar este insumo: no toda la harina del saco
            // llega al pan. El valor por omision sale de
            // `config/panaderia.php` y es 0, porque un numero que nadie
            // declaro no es un dato, es una suposicion.
            $tabla->decimal('merma_porcentaje', 6, 3)->default('0');

            $tabla->timestampsTz();

            $tabla->unique(['receta_id', 'insumo_id']);
            $tabla->index(['tenant_id', 'insumo_id']);

            // Una cantidad negativa en una receta no tiene sentido fisico.
            $tabla->check('cantidad > 0');
            $tabla->check('merma_porcentaje >= 0 AND merma_porcentaje < 100');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receta_ingredientes');
        Schema::dropIfExists('recetas');
        Schema::dropIfExists('motivos_merma');
        Schema::dropIfExists('vigencias');
    }
};