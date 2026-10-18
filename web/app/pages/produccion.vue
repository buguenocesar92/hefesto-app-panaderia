<template>
  <div>
    <h1>Produccion por tanda</h1>
    <p class="subtitulo">
      Registrar una corrida descuenta los insumos del almacen y crea el lote
      con su fecha de vencimiento.
    </p>

    <p v-if="cargando" class="vacio">Cargando...</p>

    <template v-else>
      <section>
        <h2>Registrar una corrida</h2>

        <div class="tarjeta">
          <form class="rejilla" @submit.prevent="registrar">
            <div class="grupo">
              <label class="etiqueta" for="tanda">Tanda</label>
              <select id="tanda" v-model="formulario.tanda_id" class="campo" required>
                <option value="">Elige una tanda</option>
                <option v-for="tanda in tandas" :key="tanda.id" :value="tanda.id">
                  {{ tanda.numero }} — {{ tanda.receta }}
                  ({{ tanda.cantidad_planificada ?? '?' }} planificadas)
                </option>
              </select>
            </div>

            <div class="grupo">
              <label class="etiqueta" for="cantidad">Cuanto salio del horno</label>
              <input
                id="cantidad"
                v-model.number="formulario.cantidad_producida"
                class="campo"
                type="number"
                min="0"
                step="any"
                required
              />
              <span class="tenue">
                Lo que REALMENTE salio. Si la receta rendia 40 y salieron 38,
                se carga 38: la diferencia es informacion de la panaderia.
              </span>
            </div>

            <div class="grupo">
              <label class="etiqueta" for="ubicacion">Donde quedo</label>
              <select id="ubicacion" v-model="formulario.ubicacion_id" class="campo" required>
                <option value="">Elige una ubicacion</option>
                <option v-for="ubicacion in ubicaciones" :key="ubicacion.id" :value="ubicacion.id">
                  {{ ubicacion.nombre }}
                </option>
              </select>
            </div>

            <div class="grupo">
              <button type="submit" class="boton boton--principal" :disabled="enviando">
                {{ enviando ? 'Registrando...' : 'Registrar corrida' }}
              </button>
            </div>
          </form>
        </div>

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>
        <p v-if="exito" class="aviso aviso--exito">{{ exito }}</p>
      </section>

      <section v-if="faltantes.length > 0">
        <h2>Falta para las tandas elegidas</h2>
        <p class="aviso aviso--alerta">
          No hay stock suficiente. Conviene enterarse antes de abrir el horno,
          no a mitad de la corrida.
        </p>
        <ul class="lista-simple">
          <li v-for="faltante in faltantes" :key="faltante.nombre">
            {{ faltante.nombre }}: falta {{ faltante.falta }} {{ faltante.unidad }}
          </li>
        </ul>
      </section>

      <section>
        <h2>Tandas</h2>

        <p v-if="tandas.length === 0" class="vacio">Todavia no hay tandas registradas.</p>

        <div v-else class="tarjeta">
          <table class="tabla">
            <thead>
              <tr>
                <th>Numero</th>
                <th>Receta</th>
                <th>Fecha</th>
                <th class="numero">Planificada</th>
                <th class="numero">Producida</th>
                <th>Estado</th>
                <th>Lotes</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="tanda in tandas" :key="tanda.id">
                <td>{{ tanda.numero }}</td>
                <td>{{ tanda.receta }}</td>
                <td>{{ tanda.fecha }}</td>
                <td class="numero">{{ tanda.cantidad_planificada ?? '-' }}</td>
                <td class="numero">{{ tanda.cantidad_producida ?? '-' }}</td>
                <td>
                  <span class="estado" :class="`estado--${tanda.estado}`">
                    {{ tanda.estado }}
                  </span>
                </td>
                <td>
                  <button
                    v-if="tanda.estado === 'terminada'"
                    type="button"
                    class="boton boton--chico"
                    @click="cerrarSobrante(tanda)"
                  >
                    Cerrar turno
                  </button>
                  <span v-else class="tenue">{{ tanda.lotes.length }}</span>
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
import type { Articulo, Faltante, Rendimiento, Tanda } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const cargando = ref(true);
const enviando = ref(false);
const error = ref<string | null>(null);
const exito = ref<string | null>(null);
const faltantes = ref<Faltante[]>([]);

const tandas = ref<Tanda[]>([]);
const recetas = ref<Array<{ id: string; nombre: string }>>([]);
const ubicaciones = ref<Array<{ id: string; nombre: string }>>([]);

const formulario = reactive({
  tanda_id: '',
  cantidad_producida: 0,
  ubicacion_id: '',
});

onMounted(async () => {
  const [respuestaTandas, respuestaRecetas, respuestaArticulos] = await Promise.all([
    $fetch<{ tandas: Tanda[] }>('/api/v1/tandas'),
    $fetch<{ recetas: Array<{ id: string; nombre: string }> }>('/api/v1/recetas'),
    // Las ubicaciones salen del catalogo de configuracion.
    $fetch<{ ubicaciones: Array<{ id: string; nombre: string }> }>('/api/v1/configuracion/ubicaciones'),
  ]);

  tandas.value = respuestaTandas.tandas;
  recetas.value = respuestaRecetas.recetas;
  ubicaciones.value = respuestaArticulos.ubicaciones;

  cargando.value = false;
});

async function registrar(): Promise<void> {
  enviando.value = true;
  error.value = null;
  exito.value = null;

  try {
    const respuesta = await $fetch<{ lote: { codigo: string; fecha_vencimiento: string | null } }>(
      `/api/v1/tandas/${formulario.tanda_id}/registrar`,
      {
        method: 'POST',
        body: {
          cantidad_producida: formulario.cantidad_producida,
          ubicacion_id: formulario.ubicacion_id,
          numero_tandas: 1,
        },
      },
    );

    exito.value = respuesta.lote.fecha_vencimiento
      ? `Lote ${respuesta.lote.codigo} creado. Vence el ${respuesta.lote.fecha_vencimiento}.`
      : `Lote ${respuesta.lote.codigo} creado SIN fecha de vencimiento: este producto no tiene regla configurada.`;
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  } finally {
    enviando.value = false;
  }
}

/**
 * El cierre de turno: lo que queda de las corridas anteriores o se merma
 * con su motivo, o pasa a la bodega.
 */
async function cerrarSobrante(tanda: Tanda): Promise<void> {
  error.value = null;
  exito.value = null;

  try {
    const bodega = ubicaciones.value.find((u) => u.nombre.toLowerCase() === 'bodega');

    const respuesta = await $fetch<{
      traspasados: Array<{ codigo: string; cantidad: number }>;
      mermados: Array<{ codigo: string; cantidad: number }>;
      total_sobrante: number;
    }>(`/api/v1/tandas/${tanda.id}/sobrante`, {
      method: 'POST',
      body: { bodega_id: bodega?.id ?? null },
    });

    exito.value =
      `Sobrante total: ${respuesta.total_sobrante}. ` +
      `${respuesta.traspasados.length} lote(s) pasaron a bodega y ` +
      `${respuesta.mermados.length} se mermaron por vencidos.`;
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  }
}

/**
 * Los errores de la API traen un texto en espanol que ya esta redactado para
 * la persona que lo lee. Se muestra tal cual.
 */
function mensajeDeError(fallo: unknown): string {
  const datos = fallo as { data?: { mensaje?: string } };

  return datos?.data?.mensaje ?? 'No se pudo completar la operacion.';
}

useHead({ title: 'Produccion · Gestion de Panaderia' });
</script>

<style scoped>
.lista-simple {
  list-style: none;
  padding: 0;
  margin: 0;
}
</style>