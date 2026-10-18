<template>
  <div>
    <h1>Configuracion</h1>
    <p class="subtitulo">Las reglas que decide la panaderia.</p>

    <p v-if="cargando" class="vacio">Cargando...</p>

    <template v-else>
      <section>
        <h2>Vigencias</h2>

        <p class="tenue">
          Cada producto tiene su propia regla. Dejar el campo vacio significa
          "este producto no caduca", que es una decision distinta de "caduca
          hoy". El sistema no pone plazos por su cuenta.
        </p>

        <p v-if="resumen && resumen.vigencia_por_defecto_dias === null" class="aviso aviso--alerta">
          No hay vigencia por omision configurada. Es intencional: es preferible
          un producto sin regla visible a uno con un plazo inventado.
        </p>

        <div class="tarjeta">
          <table class="tabla">
            <thead>
              <tr>
                <th>Producto</th>
                <th class="numero">Dias de vigencia</th>
                <th>Merma automatica</th>
                <th>Regla que se aplica</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="fila in vigencias" :key="fila.articulo_id">
                <td>
                  {{ fila.nombre }}
                  <span class="tenue">({{ fila.sku }})</span>
                </td>
                <td class="numero">
                  <input
                    v-model.number="fila.dias_vigencia"
                    class="campo campo--corto"
                    type="number"
                    min="0"
                    placeholder="no caduca"
                    @change="guardar(fila)"
                  />
                </td>
                <td>
                  <label>
                    <input
                      v-model="fila.merma_automatica"
                      type="checkbox"
                      @change="guardar(fila)"
                    />
                    automatica
                  </label>
                </td>
                <td>
                  <span v-if="fila.regla_efectiva === null" class="tenue">
                    sin fecha de vencimiento
                  </span>
                  <span v-else>{{ fila.regla_efectiva }} dia(s)</span>
                </td>
                <td>
                  <span v-if="guardadas.includes(fila.articulo_id)" class="tenue">guardado</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section>
        <h2>Motivos de merma</h2>

        <p class="tenue">
          Son los que hacen que el informe signifique algo. Sin motivo, "merma:
          300 unidades" no dice si se perdio pan quemado o si se conto mal.
        </p>

        <div class="tarjeta">
          <table class="tabla">
            <thead>
              <tr>
                <th>Codigo</th>
                <th>Nombre</th>
                <th>Por vencimiento</th>
                <th>Activo</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="motivo in motivos" :key="motivo.id">
                <td>{{ motivo.codigo }}</td>
                <td>{{ motivo.nombre }}</td>
                <td>{{ motivo.merma_por_vencimiento ? 'si' : 'no' }}</td>
                <td>{{ motivo.activo ? 'si' : 'no' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section>
        <h2>Unidades y ubicaciones</h2>

        <div class="rejilla">
          <div class="tarjeta">
            <h3>Unidades</h3>
            <ul class="lista-simple">
              <li v-for="unidad in unidades" :key="unidad.id">
                <strong>{{ unidad.codigo }}</strong> — {{ unidad.nombre }}
                <span class="tenue">({{ unidad.unidades_por_base }} base)</span>
              </li>
            </ul>
          </div>

          <div class="tarjeta">
            <h3>Ubicaciones</h3>
            <ul class="lista-simple">
              <li v-for="ubicacion in ubicaciones" :key="ubicacion.id">
                <strong>{{ ubicacion.codigo }}</strong> — {{ ubicacion.nombre }}
                <span class="tenue">({{ ubicacion.tipo }})</span>
              </li>
            </ul>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import type {
  MotivoMerma,
  ResumenDeConfiguracion,
  VigenciaArticulo,
} from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const cargando = ref(true);
const guardadas = ref<string[]>([]);

const resumen = ref<ResumenDeConfiguracion | null>(null);
const vigencias = ref<VigenciaArticulo[]>([]);
const motivos = ref<MotivoMerma[]>([]);
const unidades = ref<Array<{ id: string; codigo: string; nombre: string; unidades_por_base: number }>>([]);
const ubicaciones = ref<Array<{ id: string; codigo: string; nombre: string; tipo: string }>>([]);

onMounted(async () => {
  const [r, v, m, u, ub] = await Promise.all([
    $fetch<ResumenDeConfiguracion>('/api/v1/configuracion'),
    $fetch<{ articulos: VigenciaArticulo[] }>('/api/v1/configuracion/vigencias'),
    $fetch<{ motivos: MotivoMerma[] }>('/api/v1/configuracion/motivos-merma'),
    $fetch<{ unidades: typeof unidades.value }>('/api/v1/configuracion/unidades'),
    $fetch<{ ubicaciones: typeof ubicaciones.value }>('/api/v1/configuracion/ubicaciones'),
  ]);

  resumen.value = r;
  vigencias.value = v.articulos;
  motivos.value = m.motivos;
  unidades.value = u.unidades;
  ubicaciones.value = ub.ubicaciones;

  cargando.value = false;
});

async function guardar(fila: VigenciaArticulo): Promise<void> {
  // NULL es un valor valido y distinto de cero: significa "este producto no
  // caduca". El campo numerico del formulario llega vacio cuando la persona
  // lo borra, y vacio tambien es "sin regla".
  const dias = fila.dias_vigencia === null || Number.isNaN(fila.dias_vigencia)
    ? null
    : fila.dias_vigencia;

  await $fetch('/api/v1/configuracion/vigencias', {
    method: 'POST',
    body: {
      articulo_id: fila.articulo_id,
      dias_vigencia: dias,
      merma_automatica: fila.merma_automatica,
    },
  });

  if (!guardadas.value.includes(fila.articulo_id)) {
    guardadas.value.push(fila.articulo_id);
  }

  const respuesta = await $fetch<{ articulos: VigenciaArticulo[] }>('/api/v1/configuracion/vigencias');
  vigencias.value = respuesta.articulos;
}

useHead({ title: 'Configuracion · Gestion de Panaderia' });
</script>

<style scoped>
.campo--corto {
  max-width: 10rem;
  text-align: right;
}

.lista-simple {
  list-style: none;
  padding: 0;
  margin: 0;
}
</style>