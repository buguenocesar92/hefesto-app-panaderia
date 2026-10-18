<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo maestro: unidades de medida, articulos y ubicaciones.
 *
 * POR QUE UN SOLO CATALOGO DE ARTICULOS
 * -------------------------------------
 * En una panaderia la harina usada en el pan y la bolsa de harina que se
 * vende en el mostrador son EL MISMO ARTICULO, en el mismo almacen y con el
 * mismo numero de stock. Si se guardaran en dos tablas distintas, vender una
 * bolsa de harina por el mostrador y gastar harina en una tanda seria restar
 * de dos inventarios que en realidad son uno, y la merma seria invisible.
 *
 * Por eso `articulos` tiene los dos interruptores:
 *
 *  - `se_vende`: aparece en el mostrador.
 *  - `es_insumo`: se puede consumir en una receta.
 *
 * El pan de la tarde tiene `se_vende = true` y `es_insumo = false`: se vende
 * y no se usa para fabricar otra cosa. La harina tiene los dos en `true`.
 * Ninguno de los dos interruptores es obligatorio: un insumo que no se vende
 * tiene `se_vende = false`, y un producto que no se fabrica tiene
 * `es_insumo = false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('codigo', 24);
            $tabla->string('nombre', 80);

            // Cuantas unidades base trae una de esta unidad. El factor de
            // conversion vive en la UNIDAD, no en el articulo: por eso el
            // kilogramo y la bolsa pesan distinto segun el pan, y por eso dos
            // articulos que se venden en bolsas distintas usan dos unidades
            // distintas.
            $tabla->decimal('unidades_por_base', 14, 6)->default('1');

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
        });

        Schema::create('articulos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('sku', 48);
            $tabla->string('nombre', 160);
            $tabla->text('descripcion')->nullable();

            $tabla->foreignUuid('unidad_base_id')->constrained('unidades_medida')->restrictOnDelete();
            $tabla->foreignUuid('unidad_compra_id')->constrained('unidades_medida')->restrictOnDelete();
            $tabla->foreignUuid('unidad_venta_id')->constrained('unidades_medida')->restrictOnDelete();

            // Es NULLABLE a proposito. El precio es una decision comercial de
            // la panaderia y este sistema no la inventa: si no hay precio, el
            // mostrador lo tiene que cargar antes de vender. Ver docs/05.
            $tabla->decimal('precio_venta', 14, 2)->nullable();

            $tabla->decimal('stock_minimo', 14, 6)->nullable();

            $tabla->boolean('se_vende')->default(true);
            $tabla->boolean('es_insumo')->default(true);
            $tabla->boolean('activo')->default(true);

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'sku']);
            $tabla->index(['tenant_id', 'activo']);
            $tabla->index(['tenant_id', 'se_vende']);
        });

        Schema::create('ubicaciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('codigo', 32);
            $tabla->string('nombre', 120);

            // `bodega`, `mostrador` o `refrigeracion`. No es un campo libre:
            // es lo que permite responder "donde esta este lote", que es la
            // pregunta del dia a dia cuando sobra pan.
            $tabla->string('tipo', 32)->default('bodega');

            $tabla->boolean('activo')->default(true);

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicaciones');
        Schema::dropIfExists('articulos');
        Schema::dropIfExists('unidades_medida');
    }
};