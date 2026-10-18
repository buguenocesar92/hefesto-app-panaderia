<template>
  <div>
    <h1>Reportes de merma</h1>
    <p class="subtitulo">Cuanto se perdio, y en que.</p>

    <p v-if="cargando" class="vacio">Cargando...</p>

    <template v-else>
      <div class="tarjeta">
        <div class="grupo">
          <label class="etiqueta" for="desde">Desde</label>
          <input id="desde" v-model="desde" class="campo" type="date" @change="cargar" />
        </div>

        <div class="grupo">
          <label class="etiqueta" for="hasta">Hasta</label>
          <input id="hasta" v-model="hasta" class="campo" type="date" @change="cargar" />
        </div>
      </div>

      <section>
        <h2>Por motivo</h2>

        <p class="tenue">
          La separacion entre "a mano" y "automatica" es lo que permite
          responder si la perdida viene del reloj o de las manos.
        </p>

        <div class="tarjeta">
          <table class="tabla">
            <thead>
              <tr>
                <th>Motivo</th>
                <th class="numero">Total</th>
                <th class="numero">A mano</th>
                <th class="numero">Automatica</th>
                <th class="numero">% del total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="fila in porMotivo.por_motivo ?? []" :key="fila.codigo">
                <td>{{ fila.nombre }}</td>
                <td class="numero">{{ fila.cantidad }}</td>
                <td class="numero">{{ fila.manual }}</td>
                <td class="numero">{{ fila.automatica }}</td>
                <td class="numero">
                  <!--
                      NULL cuando no hubo ninguna merma en el periodo. No se
                      muestra 0 %, porque un 0 % parece un dato y seria una
                      cifra inventada.
                  -->
                  <span v-if="fila.porcentaje === null" class="tenue">-</span>
                  <span v-else>{{ fila.porcentaje }} %</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section>
        <h2>Por producto</h2>

        <div class="tarjeta">
          <table class="tabla">
            <thead>
              <tr>
                <th>Producto</th>
                <th class="numero">Unidades</th>
                <th class="numero">% del total</th>
                <th class="numero">Valor perdido</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="fila in porProducto.por_producto ?? []" :key="fila.nombre">
                <td>{{ fila.nombre }}</td>
                <td class="numero">{{ fila.cantidad }}</td>
                <td class="numero">{{ fila.porcentaje === null ? '-' : `${fila.porcentaje} %` }}</td>
                <td class="numero">
                  <!--
                      NULL cuando el producto no tiene precio cargado. Se
                      dice explicitamente: no se sabe cuanto dinero se perdio.
                  -->
                  <span v-if="fila.valor_perdido === null" class="tenue">sin precio cargado</span>
                  <span v-else>${{ fila.valor_perdido.toLocaleString('es-CL') }}</span>
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
definePageMeta({ middleware: 'auth' });

interface FilaPorMotivo {
  codigo: string;
  nombre: string;
  cantidad: number;
  manual: number;
  automatica: number;
  porcentaje: number | null;
}

interface FilaPorProducto {
  nombre: string;
  cantidad: number;
  porcentaje: number | null;
  precio_venta: number | null;
  valor_perdido: number | null;
}

const cargando = ref(true);

const desde = ref('');
const hasta = ref('');

const porMotivo = ref<{ por_motivo: FilaPorMotivo[]; total_unidades: number }>({
  por_motivo: [],
  total_unidades: 0,
});

const porProducto = ref<{ por_producto: FilaPorProducto[]; total_unidades: number }>({
  por_producto: [],
  total_unidades: 0,
});

/** Por omision, el mes en curso. Es lo que se usa si no se elige rango. */
function rango(): Record<string, string> {
  const consulta: Record<string, string> = {};

  if (desde.value) {
    consulta.desde = desde.value;
  }

  if (hasta.value) {
    consulta.hasta = hasta.value;
  }

  return consulta;
}

async function cargar(): Promise<void> {
  const consulta = rango();

  const [respuestaMotivo, respuestaProducto] = await Promise.all([
    $fetch<{ por_motivo: FilaPorMotivo[]; total_unidades: number }>('/api/v1/reportes/merma/por-motivo', {
      query: consulta,
    }),
    $fetch<{ por_producto: FilaPorProducto[]; total_unidades: number }>(
      '/api/v1/reportes/merma/por-producto',
      { query: consulta },
    ),
  ]);

  porMotivo.value = respuestaMotivo;
  porProducto.value = respuestaProducto;
}

onMounted(async () => {
  await cargar();
  cargando.value = false;
});

useHead({ title: 'Reportes de merma · Gestion de Panaderia' });
</script>