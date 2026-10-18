<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Produccion por tanda, lotes de producto y el libro de movimientos.
 *
 * LA REGLA QUE ORGANIZA TODO ESTO
 * -------------------------------
 * El stock NUNCA se edita. Se mueve.
 *
 * `lote_productos.cantidad_disponible` se cambia unicamente por un movimiento
 * de `movimientos_inventario`, y eso lo garantiza la base, no la aplicacion:
 * el gatillo `trg_lote_productos_solo_por_movimiento` revienta cualquier
 * UPDATE que no venga desde el servicio de movimientos.
 *
 * La razon es que "el pan que se quemo" y "el pan que se vendio" son la misma
 * salida de stock con motivos distintos. Si se permitiera editar la cantidad,
 * el numero final cuadraria pero la merma seria invisible. El informe de
 * merma lee los MOVIMIENTOS, no el saldo.
 *
 * La marca `app.movimiento_inventario` se pone con `set_config(..., true)`,
 * es decir con alcance de TRANSACCION: desaparece sola al terminar la
 * transaccion y no puede quedar pegada a una conexion reutilizable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tandas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('receta_id')->constrained('recetas')->restrictOnDelete();

            $tabla->string('numero', 32);

            $tabla->date('fecha');

            // Cuanto se*GANO* a producir. Lo que realmente salio del horno
            // queda en `cantidad_producida`, y son distintos a proposito: la
            // diferencia entre plan y realidad es informacion de la panaderia.
            $tabla->decimal('cantidad_planificada', 14, 6)->nullable();
            $tabla->decimal('cantidad_producida', 14, 6)->nullable();

            // `planificada`, `en_curso`, `terminada`, `anulada`.
            $tabla->string('estado', 20)->default('planificada');

            $tabla->foreignUuid('hornero_id')->constrained('usuarios')->restrictOnDelete();
            $tabla->dateTimeTz('cerrada_en')->nullable();

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'numero']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'fecha']);
        });

        Schema::create('lote_productos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('articulo_id')->constrained('articulos')->restrictOnDelete();
            $tabla->foreignUuid('ubicacion_id')->constrained('ubicaciones')->restrictOnDelete();

            // NULL cuando el lote es de una compra, no de una tanda. Un lote
            // comprado entra al mismo inventario que uno horneado.
            $tabla->foreignUuid('tanda_id')->nullable()->constrained('tandas')->restrictOnDelete();

            $tabla->string('codigo', 48);

            $tabla->decimal('cantidad_inicial', 14, 6);
            $tabla->decimal('cantidad_disponible', 14, 6);

            $tabla->date('fecha_produccion');

            // NULL = sin regla de vencimiento configurada. No se inventa un
            // plazo: el lote queda visible y a la espera. Ver
            // `vigencias` y docs/05.
            $tabla->date('fecha_vencimiento')->nullable();

            // `disponible`, `agotado`, `vencido`, `mermado`.
            $tabla->string('estado', 20)->default('disponible');

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'fecha_vencimiento']);
            $tabla->index(['articulo_id', 'estado']);

            $tabla->check('cantidad_inicial > 0');
            $tabla->check('cantidad_disponible >= 0');
            $tabla->check('cantidad_disponible <= cantidad_inicial');
            $tabla->check('fecha_vencimiento IS NULL OR fecha_vencimiento >= fecha_produccion');
        });

        Schema::create('movimientos_inventario', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->foreignUuid('articulo_id')->constrained('articulos')->restrictOnDelete();

            // NULL en los movimientos de compra, que dejan stock sin lote.
            $tabla->foreignUuid('lote_producto_id')->nullable()->constrained('lote_productos')->restrictOnDelete();
            $tabla->foreignUuid('ubicacion_id')->nullable()->constrained('ubicaciones')->restrictOnDelete();

            // Que paso: `ingreso`, `salida`, `ajuste`.
            $tabla->string('tipo', 32);

            // Hacia donde: `entrada` o `salida`. Separado del tipo porque el
            // ajuste puede ser en los dos sentidos.
            $tabla->string('sentido', 16);

            // SIEMPRE positiva. El sentido dice si suma o resta; un signo
            // escondido en la cantidad es la forma clasica de que un informe
            // de merma cuadre al reves.
            $tabla->decimal('cantidad', 14, 6);

            $tabla->foreignUuid('motivo_merma_id')->nullable()->constrained('motivos_merma')->restrictOnDelete();

            // Que documento dejo el movimiento: `TA-2026-0001`, `VE-000045`.
            $tabla->string('referencia', 64)->nullable();

            $tabla->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->restrictOnDelete();

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'articulo_id']);
            $tabla->index(['tenant_id', 'tipo']);
            $tabla->index(['lote_producto_id']);
            $tabla->index(['tenant_id', 'created_at']);

            $tabla->check('cantidad > 0');
            $tabla->check("sentido IN ('entrada', 'salida')");
        });

        // --- La garantia, en la base y no en la aplicacion -----------------

        /*
         * ¿Hay un movimiento en curso?
         *
         * Se define con `STABLE` y no `VOLATILE` porque no cambia nada: solo
         * lee. Se usa `missing_ok = true` para que, cuando la marca no esta
         * puesta, devuelva NULL en vez de lanzar excepcion: asi un
         * `UPDATE` normal recibe un mensaje claro en vez de "funcion no
         * encontrada".
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_hay_movimiento()
            RETURNS boolean
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                marca text;
            BEGIN
                marca := current_setting('app.movimiento_inventario', true);

                RETURN marca IS NOT NULL AND marca <> '';
            END;
            $$;
        SQL);

        /*
         * El saldo de un lote no se edita a mano.
         *
         * La lista de columnas es a proposito. Se protegen las que cambian
         * QUE ES el lote: la cantidad, su estado y su fecha de vencimiento.
         *
         * `ubicacion_id` NO esta en la lista, y tambien es a proposito: mover
         * un lote del mostrador a la bodega no hace que la mercaderia entre
         * ni salga del local. Si se contara como movimiento de entrada, el
         * saldo del articulo se contaria dos veces y el inventario dejaria de
         * cuadrar. Por eso un traslado cambia la ubicacion y no genera
         * movimiento; queda en el registro de auditoria.
         *
         * Sin la marca `app.movimiento_inventario`, cualquier UPDATE que
         * toque esas columnas falla. La excepcion la ve el servicio de
         * movimientos, que es el unico que la pone.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.trg_lote_productos_solo_por_movimiento()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NOT public.app_hay_movimiento() THEN
                    RAISE EXCEPTION
                        'La cantidad, el estado y el vencimiento de un lote no se editan: se mueven. Registre un movimiento en movimientos_inventario.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_lote_productos_solo_por_movimiento
            BEFORE UPDATE OF cantidad_disponible, estado, fecha_vencimiento ON public.lote_productos
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_lote_productos_solo_por_movimiento();
        SQL);

        /*
         * Un movimiento es historico: se escribe o no se corrige.
         *
         * Si se pudiera UPDATE o DELETE un movimiento, el informe de merma
         * dejaria de ser fiable: la forma de "arreglar" un dato malo seria
         * borrarlo. Para corregirlo se registra el movimiento contrario, que
         * queda a la vista.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.trg_movimientos_inmutables()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION
                    'Un movimiento de inventario no se modifica ni se borra. Registre el movimiento contrario.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER trg_movimientos_inmutables
            BEFORE UPDATE OR DELETE ON public.movimientos_inventario
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_movimientos_inmutables();
        SQL);

        /*
         * La cantidad disponible jamas puede quedar negativa, aunque el
         * servicio tenga un error. Es la ultima red: un lote que se puede
         * vender dos veces es el peor defecto posible en un mostrador.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.trg_lote_sin_saldo_negativo()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.cantidad_disponible < 0 THEN
                    RAISE EXCEPTION
                        'El lote no tiene stock suficiente: quedaban % y se pidieron %.',
                        OLD.cantidad_disponible, ABS(NEW.cantidad_disponible - OLD.cantidad_disponible)
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER trg_lote_sin_saldo_negativo
            BEFORE UPDATE OF cantidad_disponible ON public.lote_productos
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_lote_sin_saldo_negativo();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_lote_sin_saldo_negativo ON public.lote_productos;
            DROP TRIGGER IF EXISTS trg_movimientos_inmutables ON public.movimientos_inventario;
            DROP TRIGGER IF EXISTS trg_lote_productos_solo_por_movimiento ON public.lote_productos;

            DROP FUNCTION IF EXISTS public.trg_lote_sin_saldo_negativo();
            DROP FUNCTION IF EXISTS public.trg_movimientos_inmutables();
            DROP FUNCTION IF EXISTS public.trg_lote_productos_solo_por_movimiento();
            DROP FUNCTION IF EXISTS public.app_hay_movimiento();
        SQL);

        Schema::dropIfExists('movimientos_inventario');
        Schema::dropIfExists('lote_productos');
        Schema::dropIfExists('tandas');
    }
};