<template>
  <div>
    <h1>Recetas</h1>
    <p class="subtitulo">
      Cuanto rinde cada receta y cuanto hay que gastar para conseguir esa
      cantidad.
    </p>

    <p v-if="cargando" class="vacio">Cargando...</p>

    <template v-else>
      <div class="tarjeta">
        <p class="tenue">
          Elige una receta para calcular cuantas tandas puedes hacer con el
          stock que hay hoy.
        </p>

        <div class="grupo">
          <label class="etiqueta" for="receta">Receta</label>
          <select id="receta" v-model="recetaElegida" class="campo" @change="calcular">
            <option value="">Elige una receta</option>
            <option v-for="receta in recetas" :key="receta.id" :value="receta.id">
              {{ receta.nombre }} (rinde {{ receta.rendimiento_cantidad }})
            </option>
          </select>
        </div>

        <div class="grupo">
          <label class="etiqueta" for="tandas">Cuantas tandas</label>
          <input
            id="tandas"
            v-model.number="numeroTandas"
            class="campo"
            type="number"
            min="1"
            step="1"
            @change="calcular"
          />
        </div>
      </div>

      <template v-if="rendimiento">
        <section>
          <h2>Resultado</h2>

          <p v-if="!rendimiento.alcanza" class="aviso aviso--alerta">
            No alcanza el stock de {{ faltantes.length }} insumo(s). Conviene
            resolverlo antes de arrancar la corrida.
          </p>
          <p v-else class="aviso aviso--exito">Alcanza el stock para todas las insumos.</p>

          <div class="tarjeta">
            <table class="tabla">
              <thead>
                <tr>
                  <th>Que</th>
                  <th class="numero">Cantidad</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Rinde por tanda</td>
                  <td class="numero">
                    {{ rendimiento.rendimiento_tanda }} {{ rendimiento.unidad_rendimiento }}
                  </td>
                </tr>
                <tr>
                  <td><strong>Rinde en total ({{ rendimiento.numero_tandas }} tanda(s))</strong></td>
                  <td class="numero">
                    <strong>{{ rendimiento.rendimiento_total }} {{ rendimiento.unidad_rendimiento }}</strong>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section>
          <h2>Lo que hay que sacar del almacen</h2>

          <p class="tenue">
            La cantidad incluye la merma estimada del insumo: si la receta pide
            1 000 gramos y la merma es 5 %, hay que sacar 1 050. Si no, al final
            de la semana falta harina para el ultimo pan.
          </p>

          <div class="tarjeta">
            <table class="tabla">
              <thead>
                <tr>
                  <th>Insumo</th>
                  <th class="numero">Por tanda</th>
                  <th class="numero">Merma</th>
                  <th class="numero">Total a sacar</th>
                  <th class="numero">En el almacen</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="insumo in rendimiento.insumos" :key="insumo.insumo_id">
                  <td>{{ insumo.nombre }}</td>
                  <td class="numero">{{ insumo.cantidad_por_tanda }}</td>
                  <td class="numero">{{ insumo.merma_porcentaje }} %</td>
                  <td class="numero">{{ insumo.cantidad_total }}</td>
                  <td class="numero">{{ insumo.stock_disponible }}</td>
                  <td>
                    <span v-if="insumo.alcanza" class="estado estado--disponible">alcanza</span>
                    <span v-else class="estado estado--vencido">falta</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section>
          <h2>Calidad del dato</h2>
          <p class="tenue">
            No se muestra costo de produccion: el sistema no tiene el precio de
            costo de los insumos. El precio de venta de un insumo es el precio
            de la bolsa que se vende al publico, no lo que costo comprarla, y
            multiplicar uno por otro daria una cifra inventada.
          </p>
        </section>
      </template>
    </template>
  </div>
</template>

<script setup lang="ts">
import type { Faltante, Receta, Rendimiento } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const cargando = ref(true);
const recetas = ref<Receta[]>([]);
const recetaElegida = ref('');
const numeroTandas = ref(1);

const rendimiento = ref<Rendimiento | null>(null);
const faltantes = ref<Faltante[]>([]);

onMounted(async () => {
  const respuesta = await $fetch<{ recetas: Receta[] }>('/api/v1/recetas');

  recetas.value = respuesta.recetas;
  cargando.value = false;
});

async function calcular(): Promise<void> {
  rendimiento.value = null;
  faltantes.value = [];

  if (!recetaElegida.value) {
    return;
  }

  const respuesta = await $fetch<{ rendimiento: Rendimiento; faltantes: Faltante[] }>(
    `/api/v1/recetas/${recetaElegida.value}/rendimiento`,
    { query: { tandas: numeroTandas.value } },
  );

  rendimiento.value = respuesta.rendimiento;
  faltantes.value = respuesta.faltantes;
}

useHead({ title: 'Recetas · Gestion de Panaderia' });
</script>