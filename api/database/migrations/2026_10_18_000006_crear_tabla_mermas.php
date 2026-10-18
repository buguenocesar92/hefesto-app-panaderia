<?php

declare(strict_types=1);

use App\Enums\OrigenMerma;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merma: la perdida real de la panaderia.
 *
 * QUEDA SEPARADA DE LOS MOVIMIENTOS, Y NO ES Doble CARGA
 * ------------------------------------------------------
 * El movimiento dice "salieron 12 unidades de este lote". La merma dice POR
 * QUE y las dos cosas se necesitan:
 *
 *  - Para el inventario, la salida y el movimiento bastan.
 *  - Para saber si el negocio gana o pierde, hace falta el motivo. Sin el,
 *    un informe que muestra "merma: 300 unidades" no dice si se perdio pan
 *    quemado o si se vocearon 300 unidades mal contadas.
 *
 * Una merma SIEMPRE deja movimiento. No hay forma de registrar una perdida
 * que no reste stock: el servicio de merma escribe las dos cosas en la misma
 * transaccion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mermas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('articulo_id')->constrained('articulos')->restrictOnDelete();

            // NULL cuando lo que se perdio es stock sin lote (una bolsa de
            // harina que se mojo). Para lo horneado, el lote casi siempre
            // viene informado.
            $tabla->foreignUuid('lote_producto_id')->nullable()->constrained('lote_productos')->restrictOnDelete();

            // OBLIGATORIA. Un motivo NULL es exactamente el defecto que el
            // sistema tiene que impedir: la perdida sin explicacion.
            $tabla->foreignUuid('motivo_merma_id')->constrained('motivos_merma')->restrictOnDelete();

            $tabla->decimal('cantidad', 14, 6);

            $tabla->date('fecha');

            // `manual` o `automatica`. La automatica es la que hace el trabajo
            // programado cuando un lote vence. Se marca para que el informe
            // pueda separar "se perdio por el reloj" de "lo perdio alguien".
            $tabla->string('origen', 20)->default(OrigenMerma::MANUAL->value);

            // NULL solo en la merma automatica: el trabajo programado actua
            // sin que haya una persona de turno.
            $tabla->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->restrictOnDelete();

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'fecha']);
            $tabla->index(['tenant_id', 'origen']);
            $tabla->index(['motivo_merma_id']);
            $tabla->index(['articulo_id']);
            $tabla->index(['lote_producto_id']);

            $tabla->check('cantidad > 0');
            $tabla->check("origen IN ('manual', 'automatica')");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mermas');
    }
};