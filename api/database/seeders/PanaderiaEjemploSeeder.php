<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RolOperador;
use App\Enums\TipoUbicacion;
use App\Models\Articulo;
use App\Models\MotivoMerma;
use App\Models\Receta;
use App\Models\RecetaIngrediente;
use App\Models\Tenant;
use App\Models\Ubicacion;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Vigencia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de ejemplo para una panaderia.
 *
 * ---------------------------------------------------------------------
 * AVISO IMPORTANTE, LEIDO ANTES DE USAR
 * ---------------------------------------------------------------------
 * Los PRECIOS de este archivo son EJEMPLOS INVENTADOS. No son los precios
 * de ninguna panaderia real y no deben usarse para nada mas que para probar
 * la instalacion.
 *
 * Las VIGENCIAS que se crean tambien son de ejemplo. El sistema no tiene
 * ninguna vigencia por omision a proposito: cada panaderia configura la
 * suya. Ver docs/05-CONFIGURACION.md.
 *
 * Para una instalacion de verdad hay que correr:
 *   php artisan panaderia:crear-persona --empresa=<slug> --email=<correo>
 * y dar de alta las personas en Authentik.
 */
class PanaderiaEjemploSeeder extends Seeder
{
    public function run(): void
    {
        $panaderia = Tenant::factory()->create([
            'slug' => 'panaderia-ejemplo',
            'nombre' => 'Panaderia de Ejemplo',
            'razon_social' => 'Panaderia de Ejemplo Ltda.',
        ]);

        // Se escribe por la conexion directa y NO por los modelos: no hay
        // sesion ni contexto de panaderia todavia, y la RLS rechazaria un
        // `INSERT` sin contexto. Ver `ContextoTenant`.
        DB::table('usuarios')->insert([
            [
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'tenant_id' => $panaderia->id,
                'email' => 'admin@panaderia-ejemplo.cl',
                'nombre' => 'Administradora de ejemplo',
                'rol' => RolOperador::ADMINISTRADOR->value,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'tenant_id' => $panaderia->id,
                'email' => 'horno@panaderia-ejemplo.cl',
                'nombre' => 'Horrero de ejemplo',
                'rol' => RolOperador::HORNO->value,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'tenant_id' => $panaderia->id,
                'email' => 'mostrador@panaderia-ejemplo.cl',
                'nombre' => 'Vendedor de ejemplo',
                'rol' => RolOperador::MOSTRADOR->value,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $panaderiaId = $panaderia->id;
        $ahora = now();

        // --- Unidades -----------------------------------------------------
        $ids = [];

        foreach ([
            ['UN', 'Unidad', 1],
            ['G', 'Gramo', 1],
            ['KG', 'Kilo', 1000],
        ] as [$codigo, $nombre, $factor]) {
            $ids[$codigo] = \Illuminate\Support\Str::uuid()->toString();

            DB::table('unidades_medida')->insert([
                'id' => $ids[$codigo],
                'tenant_id' => $panaderiaId,
                'codigo' => $codigo,
                'nombre' => $nombre,
                'unidades_por_base' => $factor,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        // --- Ubicaciones --------------------------------------------------
        $ids['bodega'] = \Illuminate\Support\Str::uuid()->toString();
        $ids['mostrador'] = \Illuminate\Support\Str::uuid()->toString();
        $ids['frio'] = \Illuminate\Support\Str::uuid()->toString();

        DB::table('ubicaciones')->insert([
            [
                'id' => $ids['bodega'],
                'tenant_id' => $panaderiaId,
                'codigo' => 'BOD',
                'nombre' => 'Bodega',
                'tipo' => TipoUbicacion::BODEGA->value,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'id' => $ids['mostrador'],
                'tenant_id' => $panaderiaId,
                'codigo' => 'MOS',
                'nombre' => 'Mostrador',
                'tipo' => TipoUbicacion::MOSTRADOR->value,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'id' => $ids['frio'],
                'tenant_id' => $panaderiaId,
                'codigo' => 'FRI',
                'nombre' => 'Refrigeracion',
                'tipo' => TipoUbicacion::REFRIGERACION->value,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);

        // --- Articulos ----------------------------------------------------
        // OJO: los precios son EJEMPLOS INVENTADOS.
        $ids['harina'] = \Illuminate\Support\Str::uuid()->toString();
        $ids['pan'] = \Illuminate\Support\Str::uuid()->toString();
        $ids['torta'] = \Illuminate\Support\Str::uuid()->toString();

        DB::table('articulos')->insert([
            [
                'id' => $ids['harina'],
                'tenant_id' => $panaderiaId,
                'sku' => 'INS-HARINA',
                'nombre' => 'Harina sin polvos',
                'descripcion' => 'Se consume en receta. Tambien se vende en bolsa.',
                'unidad_base_id' => $ids['G'],
                'unidad_compra_id' => $ids['KG'],
                'unidad_venta_id' => $ids['KG'],
                // Precio de EJEMPLO, del producto que se vende al publico.
                'precio_venta' => 2500,
                'stock_minimo' => 20000,
                'se_vende' => true,
                'es_insumo' => true,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'id' => $ids['pan'],
                'tenant_id' => $panaderiaId,
                'sku' => 'PAN-MARRAQUETA',
                'nombre' => 'Marraqueta',
                'descripcion' => null,
                'unidad_base_id' => $ids['UN'],
                'unidad_compra_id' => $ids['UN'],
                'unidad_venta_id' => $ids['UN'],
                // Precio de EJEMPLO.
                'precio_venta' => 350,
                'stock_minimo' => null,
                'se_vende' => true,
                'es_insumo' => false,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'id' => $ids['torta'],
                'tenant_id' => $panaderiaId,
                'sku' => 'TORTA-CUMPLE',
                'nombre' => 'Torta de cumpleanos',
                'descripcion' => null,
                'unidad_base_id' => $ids['UN'],
                'unidad_compra_id' => $ids['UN'],
                'unidad_venta_id' => $ids['UN'],
                // Precio de EJEMPLO.
                'precio_venta' => 45000,
                'stock_minimo' => null,
                'se_vende' => true,
                'es_insumo' => false,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);

        // --- Vigencias ----------------------------------------------------
        // De EJEMPLO. Cada panaderia configura las suyas.
        DB::table('vigencias')->insert([
            [
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'tenant_id' => $panaderiaId,
                'articulo_id' => $ids['pan'],
                'dias_vigencia' => 1,
                'merma_automatica' => true,
                'notas' => 'El pan caduca al dia siguiente. Valor de ejemplo.',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'tenant_id' => $panaderiaId,
                'articulo_id' => $ids['torta'],
                'dias_vigencia' => 3,
                'merma_automatica' => false,
                'notas' => 'La torta se entrega el mismo dia. Valor de ejemplo.',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            // La harina NO tiene fila a proposito: no caduca. Asi se ve en
            // la configuracion como un producto sin regla.
        ]);

        // --- Motivos de merma ---------------------------------------------
        foreach ([
            ['vencido', 'Vencido', true, 'La mercaderia paso su fecha de vencimiento.'],
            ['quemado', 'Quemado en el horno', false, 'Salio quemado y no se puede vender.'],
            ['caido', 'Caido al piso', false, 'Se cae al piso y no se puede vender.'],
            ['error_conteo', 'Error de conteo', false, 'Se conto mal dos veces.'],
        ] as [$codigo, $nombre, $porVencimiento, $descripcion]) {
            DB::table('motivos_merma')->insert([
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'tenant_id' => $panaderiaId,
                'codigo' => $codigo,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'merma_por_vencimiento' => $porVencimiento,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        // --- Receta de ejemplo --------------------------------------------
        $idReceta = \Illuminate\Support\Str::uuid()->toString();

        DB::table('recetas')->insert([
            'id' => $idReceta,
            'tenant_id' => $panaderiaId,
            'articulo_id' => $ids['pan'],
            'nombre' => 'Marraqueta',
            'version' => 1,
            // "Esta receta rinde 40 unidades". Cifra de EJEMPLO.
            'rendimiento_cantidad' => 40,
            'rendimiento_unidad_id' => $ids['UN'],
            'notas' => 'Receta de ejemplo.',
            'activa' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        DB::table('receta_ingredientes')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $panaderiaId,
            'receta_id' => $idReceta,
            'insumo_id' => $ids['harina'],
            // Cantidades de EJEMPLO, en gramos.
            'cantidad' => 20000,
            'unidad_id' => $ids['G'],
            // Merma estimada de EJEMPLO: 5 %.
            'merma_porcentaje' => 5,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $this->command?->info('Panaderia de ejemplo creada.');
        $this->command?->line('  Panaderia: panaderia-ejemplo');
        $this->command?->line('  Personas: admin@, horno@ y mostrador@panaderia-ejemplo.cl');
        $this->command?->warn('  Los PRECIOS son ejemplos inventados, no precios reales.');
        $this->command?->line('  Las personas todavia tienen que existir en Authentik para poder entrar.');
    }
}