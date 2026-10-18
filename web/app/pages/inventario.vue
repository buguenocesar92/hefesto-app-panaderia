<template>
  <div>
    <h1>Inventario</h1>
    <p class="subtitulo">
      Saldos y lotes. El saldo se lee del libro de movimientos, no de una
      columna que se pueda desincronizar.
    </p>

    <p v-if="cargando" class="vacio">Cargando...</p>

    <template v-else>
      <!--
          LA CADUCIDAD VA PRIMERO. Es la pregunta del dia y la que decide si
          algo se puede vender o ya es perdida.
      -->
      <section>
        <h2>Caducidad</h2>

        <div v-if="caducidad.vencidos.length === 0 && caducidad.por_vencer.length === 0" class="vacio">
          No hay lotes vencidos ni por vencer.
        </div>

        <template v-else>
          <h3 v-if="caducidad.vencidos.length > 0">Vencidos: no se pueden vender</h3>

          <div v-if="caducidad.vencidos.length > 0" class="tarjeta" style="margin-bottom: 1rem">
            <table class="tabla">
              <thead>
                <tr>
                  <th>Lote</th>
                  <th>Producto</th>
                  <th class="numero">Disponible</th>
                  <th>Vencio</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="lote in caducidad.vencidos" :key="lote.codigo">
                  <td>{{ lote.codigo }}</td>
                  <td>{{ lote.articulo }}</td>
                  <td class="numero">{{ lote.cantidad_disponible }}</td>
                  <td>{{ lote.fecha_vencimiento }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <h3 v-if="caducidad.por_vencer.length > 0">Por vencer</h3>

          <div v-if="caducidad.por_vencer.length > 0" class="tarjeta">
            <table class="tabla">
              <thead>
                <tr>
                  <th>Lote</th>
                  <th>Producto</th>
                  <th class="numero">Disponible</th>
                  <th>Vence</th>
                  <th class="numero">Dias</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="lote in caducidad.por_vencer" :key="lote.codigo">
                  <td>{{ lote.codigo }}</td>
                  <td>{{ lote.articulo }}</td>
                  <td class="numero">{{ lote.cantidad_disponible }}</td>
                  <td>{{ lote.fecha_vencimiento }}</td>
                  <td class="numero">{{ lote.dias ?? '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>

      <section>
        <h2>Saldos</h2>

        <p v-if="articulosSinRegla.length > 0" class="aviso aviso--alerta">
          {{ articulosSinRegla.length }} producto(s) no tienen regla de
          vencimiento configurada. Sus lotes salen SIN fecha de vencimiento y no
          caducan solos. Un administrador lo configura en
          <NuxtLink to="/configuracion">Configuracion</NuxtLink>.
        </p>

        <div class="tarjeta">
          <table class="tabla">
            <thead>
              <tr>
                <th>Codigo</th>
                <th>Producto</th>
                <th class="numero">Saldo</th>
                <th class="numero">Minimo</th>
                <th>Vigencia</th>
                <th>Precio</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="saldo in saldos" :key="saldo.articulo_id">
                <td>{{ saldo.sku }}</td>
                <td>{{ saldo.nombre }}</td>
                <td class="numero">{{ saldo.saldo }}</td>
                <td class="numero">{{ saldo.stock_minimo ?? '-' }}</td>
                <td class="tenue">
                  {{ saldo.dias_vigencia === null ? 'sin regla' : `${saldo.dias_vigencia} dia(s)` }}
                </td>
                <td>
                  <span v-if="saldo.tiene_precio" class="numero">
                    ${{ saldo.precio_venta?.toLocaleString('es-CL') }}
                  </span>
                  <span v-else class="tenue">sin precio</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section>
        <h2>Lotes</h2>

        <p v-if="lotes.length === 0" class="vacio">No hay lotes con mercaderia.</p>

        <div v-else class="tarjeta">
          <table class="tabla">
            <thead>
              <tr>
                <th>Lote</th>
                <th>Producto</th>
                <th>Ubicacion</th>
                <th class="numero">Disponible</th>
                <th>Vence</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="lote in lotes" :key="lote.id">
                <td>{{ lote.codigo }}</td>
                <td>{{ lote.articulo }}</td>
                <td>{{ lote.ubicacion }}</td>
                <td class="numero">{{ lote.cantidad_disponible }}</td>
                <td>
                  <span v-if="lote.fecha_vencimiento">{{ lote.fecha_vencimiento }}</span>
                  <span v-else class="tenue">no caduca</span>
                </td>
                <td>
                  <span class="estado" :class="`estado--${lote.estado}`">{{ lote.estado }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import type { Articulo, Lote, RevisionDeCaducidad, SaldoDeArticulo } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const cargando = ref(true);

const saldos = ref<SaldoDeArticulo[]>([]);
const lotes = ref<Lote[]>([]);
const caducidad = ref<RevisionDeCaducidad>({ por_vencer: [], vencidos: [] });
const articulos = ref<Articulo[]>([]);

const articulosSinRegla = computed(() =>
  articulos.value.filter((a) => !a.tiene_regla_vigencia),
);

onMounted(async () => {
  const [respuestaSaldos, respuestaLotes, respuestaCaducidad, respuestaArticulos] = await Promise.all([
    $fetch<{ articulos: SaldoDeArticulo[] }>('/api/v1/inventario'),
    $fetch<{ lotes: Lote[] }>('/api/v1/inventario/lotes?solo_disponibles=1'),
    $fetch<RevisionDeCaducidad>('/api/v1/inventario/caducando'),
    $fetch<{ articulos: Articulo[] }>('/api/v1/articulos'),
  ]);

  saldos.value = respuestaSaldos.articulos;
  lotes.value = respuestaLotes.lotes;
  caducidad.value = respuestaCaducidad;
  articulos.value = respuestaArticulos.articulos;

  cargando.value = false;
});

useHead({ title: 'Inventario · Gestion de Panaderia' });
</script>