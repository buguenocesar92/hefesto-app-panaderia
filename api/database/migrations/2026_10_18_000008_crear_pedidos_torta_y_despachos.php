<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos de torta y despachos.
 *
 * POR QUE ESTO ESTA SEPARADO DE LAS VENTAS DE MOSTRADOR
 * ---------------------------------------------------
 * Una venta de mostrador es pan que sale ahora. Un pedido de torta es un
 * encargo con fecha: se toma hoy, se entrega en tres dias, se produce el dia
 * anterior y a veces tiene que adelantarse porque la torta del cliente se
 * terminaba temprano.
 *
 * Mezclarlos obliga a llenar de NULL las columnas de fecha, cliente y
 * anticipo en cada venta de pan. Y el problema real es otro: un pedido que no
 * esta agendado no existe, y una torta perdida es la perdida mas cara del
 * negocio, muy por encima de la merma de pan.
 *
 * `saldo` se guarda aunque se pueda calcular: el informe de "cuanto falta
 * cobrar" se consulta sobre una columna y no sobre una resta en cada fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_torta', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('numero', 32);

            $tabla->string('cliente_nombre', 160);

            // Telefono, correo o el que sea. Es el unico contacto: sin el no
            // hay manera de avisar que la torta salio antes de tiempo.
            $tabla->string('cliente_contacto', 160)->nullable();

            $tabla->foreignUuid('articulo_id')->constrained('articulos')->restrictOnDelete();

            $tabla->unsignedInteger('cantidad')->default(1);

            // Tamano y sabor son texto libre y no catalogos: cada panaderia
            // los llama distinto ("12 pulgadas", "mediana", "grande", "1 kilo")
            // y una tabla de tamanos obligaria a elegir uno para siempre.
            $tabla->string('tamano', 64)->nullable();
            $tabla->string('sabor', 120)->nullable();

            // El diseno va en texto. Guardar una imagen exigiria decidir
            // ahora el almacenamiento, los permisos y el respaldo de un
            // archivo binario: queda como pendiente en docs/10.
            $tabla->text('diseno')->nullable();
            $tabla->text('mensaje_personalizado')->nullable();

            $tabla->date('fecha_entrega');

            // Texto "HH:MM", no TIME: se guarda tal como el cliente lo pidio
            // ("mediodia", "16:30") y el pedido se confirma en una conversa.
            $tabla->string('hora_entrega', 5)->nullable();

            // `borrador`, `confirmado`, `en_produccion`, `listo`, `entregado`,
            // `anulado`.
            $tabla->string('estado', 24)->default('borrador');

            $tabla->decimal('monto_total', 14, 2)->default('0');
            $tabla->decimal('anticipo', 14, 2)->default('0');
            $tabla->decimal('saldo', 14, 2)->default('0');

            $tabla->foreignUuid('creado_por_id')->constrained('usuarios')->restrictOnDelete();
            $tabla->dateTimeTz('cerrado_en')->nullable();

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'numero']);

            // El indice que usa la agenda: "que tengo que entregar hoy y esta
            // semana". Sin el, es un recorrido de toda la tabla.
            $tabla->index(['tenant_id', 'fecha_entrega']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'estado', 'fecha_entrega']);

            $tabla->check('cantidad > 0');
            $tabla->check('monto_total >= 0');
            $tabla->check('anticipo >= 0');
            $tabla->check('saldo >= 0');
        });

        Schema::create('despachos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('pedido_torta_id')->constrained('pedidos_torta')->restrictOnDelete();

            $tabla->date('fecha');
            $tabla->string('hora_entrega', 5)->nullable();

            $tabla->text('direccion')->nullable();
            $tabla->string('entregado_a', 160)->nullable();

            // De que lote salio la torta. NULL cuando la torta se hizo para
            // el pedido y no salio de un lote del mostrador.
            $tabla->foreignUuid('lote_producto_id')->nullable()->constrained('lote_productos')->restrictOnDelete();

            $tabla->foreignUuid('recibido_por_id')->nullable()->constrained('usuarios')->restrictOnDelete();

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'fecha']);
            $tabla->index(['pedido_torta_id']);

            // Un pedido no se despacha dos veces. Si la torta se entrega
            // en dos tramos, es otro pedido.
            $tabla->unique(['tenant_id', 'pedido_torta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despachos');
        Schema::dropIfExists('pedidos_torta');
    }
};