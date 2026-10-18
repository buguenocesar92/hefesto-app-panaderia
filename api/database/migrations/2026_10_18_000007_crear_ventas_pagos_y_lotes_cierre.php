<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venta en mostrador, pagos y lotes de cierre.
 *
 * POR QUE CADA LINEA GUARDA SU LOTE
 * ---------------------------------
 * `venta_items.lote_producto_id` no es un detalle. Sin el lote no se puede
 * saber que pan se vendio ni cuando vence lo que queda. Es lo que permite,
 * despues, responder "de los 30 panes que vendimos el martes, cuantos
 * eran del lote que se quemo".
 *
 * El descuento no se guarda en la linea sino en el LOTE DE CIERRE, que es
 * donde de verdad se aplica: cuando a la panaderia le quedan panes del dia
 * anterior, baja el precio de todo lo que esta en ese lote de cierre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_cierre', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('nombre', 120);
            $tabla->date('fecha');

            // El descuento se escribe como porcentaje para que la interfaz
            // muestre "30 % de descuento" y no solo un monto raro que nadie
            // reconoce despues. El descuento ya aplicado queda en `ventas`.
            $tabla->decimal('descuento_porcentaje', 6, 3)->default('0');

            // `abierto` o `cerrado`. Un lote abierto recibe ventas; uno
            // cerrado, no. Cerrar es lo que permite levantar el arqueo del
            // dia.
            $tabla->string('estado', 20)->default('abierto');

            $tabla->foreignUuid('abierto_por_id')->constrained('usuarios')->restrictOnDelete();
            $tabla->dateTimeTz('cerrado_en')->nullable();

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'fecha']);

            $tabla->check('descuento_porcentaje >= 0 AND descuento_porcentaje < 100');
        });

        Schema::create('ventas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('numero', 32);

            // NULL cuando la venta se hizo sin lote de cierre.
            $tabla->foreignUuid('lote_cierre_id')->nullable()->constrained('lotes_cierre')->restrictOnDelete();

            $tabla->date('fecha');

            // `abierta` o `cerrada`. Se abre antes de cobrar y se cierra con
            // el pago: mientras esta abierta, la caja esta descuadrada.
            $tabla->string('estado', 20)->default('abierta');

            $tabla->decimal('bruto', 14, 2)->default('0');
            $tabla->decimal('descuento', 14, 2)->default('0');
            $tabla->decimal('total', 14, 2)->default('0');

            $tabla->dateTimeTz('abierta_en');
            $tabla->dateTimeTz('cerrada_en')->nullable();

            $tabla->foreignUuid('vendedor_id')->constrained('usuarios')->restrictOnDelete();

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'numero']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'fecha']);
            $tabla->index(['lote_cierre_id']);

            $tabla->check('bruto >= 0');
            $tabla->check('descuento >= 0');
            $tabla->check('total >= 0');
        });

        Schema::create('venta_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('venta_id')->constrained('ventas')->restrictOnDelete();

            $tabla->foreignUuid('articulo_id')->constrained('articulos')->restrictOnDelete();

            // De que lote salio. Ver la nota de la cabecera.
            $tabla->foreignUuid('lote_producto_id')->constrained('lote_productos')->restrictOnDelete();

            $tabla->decimal('cantidad', 14, 6);

            // El precio se COPIA al momento de la venta. Si manana suben
            // los precios, la venta de ayer tiene que seguir cuadrando con
            // el precio de ayer.
            $tabla->decimal('precio_unitario', 14, 2);
            $tabla->decimal('descuento_unitario', 14, 2)->default('0');
            $tabla->decimal('subtotal', 14, 2);

            $tabla->timestampsTz();

            $tabla->index(['venta_id']);
            $tabla->index(['lote_producto_id']);
            $tabla->index(['articulo_id']);

            $tabla->check('cantidad > 0');
            $tabla->check('precio_unitario >= 0');
            $tabla->check('descuento_unitario >= 0');
        });

        Schema::create('pagos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('venta_id')->constrained('ventas')->restrictOnDelete();

            // `efectivo`, `debito`, `credito`, `transferencia`. Texto y no
            // tabla: los medios de pago los decide la panaderia y cambiar la
            // lista no es un desarrollo.
            $tabla->string('metodo', 32);

            $tabla->decimal('monto', 14, 2);

            // Solo en efectivo: lo que entre y lo que se devuelve.
            $tabla->decimal('recibido', 14, 2)->nullable();
            $tabla->decimal('vuelto', 14, 2)->nullable();

            $tabla->timestampsTz();

            $tabla->index(['venta_id']);
            $tabla->index(['tenant_id', 'metodo']);

            $tabla->check('monto > 0');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('venta_items');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('lotes_cierre');
    }
};