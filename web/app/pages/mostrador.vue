<template>
  <div>
    <h1>Mostrador</h1>
    <p class="subtitulo">Venta rapida, sin fila.</p>

    <p v-if="cargando" class="vacio">Cargando...</p>

    <template v-else>
      <div v-if="avisos.length > 0" class="aviso aviso--alerta">
        <ul class="lista-corta">
          <li v-for="aviso in avisos" :key="aviso">{{ aviso }}</li>
        </ul>
      </div>

      <section>
        <h2>Abrir una venta</h2>

        <div class="tarjeta">
          <div class="grupo">
            <label class="etiqueta" for="articulo">Producto</label>
            <select id="articulo" v-model="nuevoArticuloId" class="campo">
              <option value="">Elige un producto</option>
              <option
                v-for="articulo in vendibles"
                :key="articulo.id"
                :value="articulo.id"
                :disabled="!articulo.tiene_precio"
              >
                {{ articulo.nombre }} —
                {{ articulo.tiene_precio ? `$${articulo.precio_venta}` : 'sin precio' }}
                (saldo {{ articulo.saldo }})
              </option>
            </select>
            <span class="tenue">
              Los que no tienen precio salen desactivados: sin precio no se
              puede cobrar.
            </span>
          </div>

          <div class="grupo">
            <label class="etiqueta" for="cantidad">Cuanto</label>
            <input id="cantidad" v-model.number="nuevaCantidad" class="campo" type="number" min="0" step="any" />
          </div>

          <div class="grupo">
            <button type="button" class="boton" @click="agregarLinea">Agregar</button>
          </div>
        </div>

        <div v-if="lineas.length > 0" class="tarjeta" style="margin-top: 1rem">
          <table class="tabla">
            <thead>
              <tr>
                <th>Producto</th>
                <th class="numero">Cantidad</th>
                <th class="numero">Subtotal estimado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(linea, indice) in lineas" :key="indice">
                <td>{{ linea.nombre }}</td>
                <td class="numero">{{ linea.cantidad }}</td>
                <td class="numero">
                  {{ linea.precio === null ? 'sin precio' : `$${linea.precio * linea.cantidad}` }}
                </td>
                <td>
                  <button type="button" class="boton boton--chico" @click="lineas.splice(indice, 1)">
                    Quitar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>

          <div class="grupo">
            <button
              type="button"
              class="boton boton--principal"
              :disabled="enviando || lineas.length === 0"
              @click="abrirVenta"
            >
              {{ enviando ? 'Abriendo...' : 'Abrir venta' }}
            </button>
          </div>
        </div>
      </section>

      <section v-if="ventaAbierta">
        <h2>Venta abierta {{ ventaAbierta.numero }}</h2>

        <p class="tenue">
          Abierta hace {{ ventaAbierta.minutos_abierta }} minuto(s). El pan ya
          salio del almacen aunque el pago este pendiente.
        </p>

        <div class="tarjeta">
          <table class="tabla">
            <tbody>
              <tr>
                <td>Bruto</td>
                <td class="numero">${{ ventaAbierta.bruto.toLocaleString('es-CL') }}</td>
              </tr>
              <tr>
                <td>Descuento del lote de cierre</td>
                <td class="numero">-${{ ventaAbierta.descuento.toLocaleString('es-CL') }}</td>
              </tr>
              <tr>
                <td><strong>Total</strong></td>
                <td class="numero"><strong>${{ ventaAbierta.total.toLocaleString('es-CL') }}</strong></td>
              </tr>
            </tbody>
          </table>

          <div class="rejilla" style="margin-top: 1rem">
            <div class="grupo">
              <label class="etiqueta" for="metodo">Como paga</label>
              <select id="metodo" v-model="pago.metodo" class="campo">
                <option value="efectivo">Efectivo</option>
                <option value="debito">Debito</option>
                <option value="credito">Credito</option>
                <option value="transferencia">Transferencia</option>
              </select>
            </div>

            <div v-if="pago.metodo === 'efectivo'" class="grupo">
              <label class="etiqueta" for="recibido">Cuanto entrega</label>
              <input
                id="recibido"
                v-model.number="pago.recibido"
                class="campo"
                type="number"
                min="0"
                step="any"
              />
              <span v-if="vuelto !== null" class="tenue">
                Vuelto: ${{ vuelto.toLocaleString('es-CL') }}
              </span>
            </div>

            <div class="grupo">
              <button type="button" class="boton boton--principal" :disabled="cobrando" @click="cobrar">
                {{ cobrando ? 'Cobrando...' : 'Cobrar' }}
              </button>
            </div>
          </div>
        </div>
      </section>

      <section v-if="error" class="aviso aviso--error">{{ error }}</section>

      <section>
        <h2>Ultimas ventas</h2>

        <p v-if="ventas.length === 0" class="vacio">Todavia no hay ventas.</p>

        <div v-else class="tarjeta">
          <table class="tabla">
            <thead>
              <tr>
                <th>Numero</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th class="numero">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="venta in ventas" :key="venta.id">
                <td>{{ venta.numero }}</td>
                <td>{{ venta.fecha }}</td>
                <td>
                  <span class="estado" :class="`estado--${venta.estado}`">{{ venta.estado }}</span>
                </td>
                <td class="numero">${{ venta.total.toLocaleString('es-CL') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import type { Articulo, LoteCierre, Venta } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const cargando = ref(true);
const enviando = ref(false);
const cobrando = ref(false);
const error = ref<string | null>(null);

const articulos = ref<Articulo[]>([]);
const ventas = ref<Venta[]>([]);
const lotesCierre = ref<LoteCierre[]>([]);
const ventaAbierta = ref<Venta | null>(null);

const avisos = computed<string[]>(() => {
  const lista: string[] = [];

  for (const articulo of articulos.value) {
    if (!articulo.tiene_precio && articulo.se_vende) {
      lista.push(`${articulo.nombre} no tiene precio cargado y no se puede cobrar.`);
    }
  }

  return lista;
});

const vendibles = computed(() => articulos.value.filter((a) => a.se_vende && a.activo));

interface Linea {
  articulo_id: string;
  nombre: string;
  cantidad: number;
  precio: number | null;
}

const lineas = ref<Linea[]>([]);

const nuevoArticuloId = ref('');
const nuevaCantidad = ref(1);

const pago = reactive({
  metodo: 'efectivo' as 'efectivo' | 'debito' | 'credito' | 'transferencia',
  recibido: 0,
});

const vuelto = computed<number | null>(() => {
  if (pago.metodo !== 'efectivo' || !ventaAbierta.value) {
    return null;
  }

  const diferencia = pago.recibido - ventaAbierta.value.total;

  return diferencia >= 0 ? diferencia : null;
});

onMounted(async () => {
  const [respuestaArticulos, respuestaVentas, respuestaLotes] = await Promise.all([
    $fetch<{ articulos: Articulo[] }>('/api/v1/articulos?solo_vendibles=1'),
    $fetch<{ ventas: Venta[] }>('/api/v1/ventas'),
    $fetch<{ lotes_cierre: LoteCierre[] }>('/api/v1/lotes-cierre'),
  ]);

  articulos.value = respuestaArticulos.articulos;
  ventas.value = respuestaVentas.ventas;
  lotesCierre.value = respuestaLotes.lotes_cierre.filter((l) => l.estado === 'abierto');

  cargando.value = false;
});

function agregarLinea(): void {
  const articulo = articulos.value.find((a) => a.id === nuevoArticuloId.value);

  if (!articulo) {
    return;
  }

  const existente = lineas.value.find((l) => l.articulo_id === articulo.id);

  if (existente) {
    existente.cantidad += nuevaCantidad.value;
  } else {
    lineas.value.push({
      articulo_id: articulo.id,
      nombre: articulo.nombre,
      cantidad: nuevaCantidad.value,
      precio: articulo.precio_venta,
    });
  }

  nuevoArticuloId.value = '';
  nuevaCantidad.value = 1;
}

async function abrirVenta(): Promise<void> {
  enviando.value = true;
  error.value = null;

  const loteAbierto = lotesCierre.value[0];

  try {
    ventaAbierta.value = await $fetch<Venta>('/api/v1/ventas', {
      method: 'POST',
      body: {
        items: lineas.value.map((l) => ({ articulo_id: l.articulo_id, cantidad: l.cantidad })),
        lote_cierre_id: loteAbierto?.id ?? null,
      },
    });

    pago.recibido = ventaAbierta.value.total;
    lineas.value = [];
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  } finally {
    enviando.value = false;
  }
}

async function cobrar(): Promise<void> {
  if (!ventaAbierta.value) {
    return;
  }

  cobrando.value = true;
  error.value = null;

  try {
    ventaAbierta.value = await $fetch<Venta>(`/api/v1/ventas/${ventaAbierta.value.id}/pagar`, {
      method: 'POST',
      body: {
        metodo: pago.metodo,
        recibido: pago.metodo === 'efectivo' ? pago.recibido : null,
      },
    });

    ventaAbierta.value = null;

    const respuesta = await $fetch<{ ventas: Venta[] }>('/api/v1/ventas');
    ventas.value = respuesta.ventas;
  } catch (fallo) {
    error.value = mensajeDeError(fallo);
  } finally {
    cobrando.value = false;
  }
}

function mensajeDeError(fallo: unknown): string {
  return (fallo as { data?: { mensaje?: string } })?.data?.mensaje ?? 'No se pudo completar la operacion.';
}

useHead({ title: 'Mostrador · Gestion de Panaderia' });
</script>

<style scoped>
.lista-corta {
  margin: 0;
  padding-left: 1.25rem;
}
</style>