<template>
  <div>
    <h1>Merma</h1>
    <p class="subtitulo">
      La perdida real del negocio. El motivo es obligatorio: una perdida sin
      explicacion no sirve para nada.
    </p>

    <p v-if="cargando" class="vacio">Cargando...</p>

    <template v-else>
      <section>
        <h2>Registrar una perdida</h2>

        <div class="tarjeta">
          <form class="rejilla" @submit.prevent="registrar">
            <div class="grupo">
              <label class="etiqueta" for="articulo">Producto</label>
              <select id="articulo" v-model="formulario.articulo_id" class="campo" required>
                <option value="">Elige un producto</option>
                <option v-for="articulo in articulos" :key="articulo.id" :value="articulo.id">
                  {{ articulo.nombre }} (saldo {{ articulo.saldo }})
                </option>
              </select>
            </div>

            <div class="grupo">
              <label class="etiqueta" for="lote">Lote</label>
              <select id="lote" v-model="formulario.lote_producto_id" class="campo">
                <option value="">Sin lote (stock a granel)</option>
                <option v-for="lote in lotes" :key="lote.id" :value="lote.id">
                  {{ lote.codigo }} — {{ lote.articulo }}
                  ({{ lote.cantidad_disponible }} disponibles)
                </option>
              </select>
            </div>

            <div class="grupo">
              <label class="etiqueta" for="motivo">Por que se perdio</label>
              <select id="motivo" v-model="formulario.motivo_merma_id" class="campo" required>
                <option value="">Elige un motivo</option>
                <option v-for="motivo in motivos" :key="motivo.id" :value="motivo.id">
                  {{ motivo.nombre }}
                </option>
              </select>
              <span v-if="motivos.length === 0" class="aviso aviso--alerta">
                No hay motivos de merma configurados. Un administrador tiene
                que crearlos antes: sin motivo, la perdida no queda explicada.
              </span>
            </div>

            <div class="grupo">
              <label class="etiqueta" for="cantidad">Cuanto</label>
              <input
                id="cantidad"
                v-model.number="formulario.cantidad"
                class="campo"
                type="number"
                min="0"
                step="any"
                required
              />
            </div>

            <div class="grupo">
              <button
                type="submit"
                class="boton boton--principal"
                :disabled="enviando || motivos.length === 0"
              >
                {{ enviando ? 'Registrando...' : 'Registrar merma' }}
              </button>
            </div>
          </form>
        </div>

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>
        <p v-if="exito" class="aviso aviso--exito">{{ exito }}</p>
      </section>

      <section>
        <h2>Mermas registradas</h2>

        <p v-if="mermas.length === 0" class="vacio">No hay mermas registradas.</p>

        <div v-else class="tarjeta">
          <table class="tabla">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Producto</th>
                <th>Lote</th>
                <th>Motivo</th>
                <th class="numero">Cantidad</th>
                <th class="numero">Valor</th>
                <th>Origen</th>
                <th>Quien</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="merma in mermas" :key="merma.id">
                <td>{{ merma.fecha }}</td>
                <td>{{ merma.articulo }}</td>
                <td>{{ merma.lote ?? '-' }}</td>
                <td>{{ merma.motivo }}</td>
                <td class="numero">{{ merma.cantidad }}</td>
                <td class="numero">
                  <!--
                      NULL significa que el producto no tiene precio
                      cargado. Se dice explicitamente en vez de mostrar 0:
                      un 0 seria afirmar que no se perdio nada.
                  -->
                  <span v-if="merma.valor_perdido === null" class="tenue">sin precio</span>
                  <span v-else>${{ merma.valor_perdido.toLocaleString('es-CL') }}</span>
                </td>
                <td>{{ merma.origen === 'automatica' ? 'automatica' : 'a mano' }}</td>
                <td>{{ merma.usuario ?? 'sistema' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import type { Articulo, Lote, Merma, MotivoMerma } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const cargando = ref(true);
const enviando = ref(false);
const error = ref<string | null>(null);
const exito = ref<string | null>(null);

const mermas = ref<Merma[]>([]);
const articulos = ref<Articulo[]>([]);
const lotes = ref<Lote[]>([]);
const motivos = ref<MotivoMerma[]>([]);

const formulario = reactive({
  articulo_id: '',
  lote_producto_id: '',
  motivo_merma_id: '',
  cantidad: 0,
});

onMounted(async () => {
  const [respuestaMermas, respuestaArticulos, respuestaLotes, respuestaMotivos] = await Promise.all([
    $fetch<{ mermas: Merma[] }>('/api/v1/mermas'),
    $fetch<{ articulos: Articulo[] }>('/api/v1/articulos'),
    $fetch<{ lotes: Lote[] }>('/api/v1/inventario/lotes?solo_disponibles=1'),
    $fetch<{ motivos: MotivoMerma[] }>('/api/v1/mermas/motivos'),
  ]);

  mermas.value = respuestaMermas.mermas;
  articulos.value = respuestaArticulos.articulos;
  lotes.value = respuestaLotes.lotes;
  motivos.value = respuestaMotivos.motivos;

  cargando.value = false;
});

async function registrar(): Promise<void> {
  enviando.value = true;
  error.value = null;
  exito.value = null;

  try {
    await $fetch('/api/v1/mermas', {
      method: 'POST',
      body: {
        articulo_id: formulario.articulo_id,
        lote_producto_id: formulario.lote_producto_id || null,
        motivo_merma_id: formulario.motivo_merma_id,
        cantidad: formulario.cantidad,
      },
    });

    exito.value = 'Merma registrada y stock descontado.';

    const respuesta = await $fetch<{ mermas: Merma[] }>('/api/v1/mermas');
    mermas.value = respuesta.mermas;

    formulario.cantidad = 0;
  } catch (fallo) {
    error.value = (fallo as { data?: { mensaje?: string } })?.data?.mensaje
      ?? 'No se pudo registrar la merma.';
  } finally {
    enviando.value = false;
  }
}

useHead({ title: 'Merma · Gestion de Panaderia' });
</script>