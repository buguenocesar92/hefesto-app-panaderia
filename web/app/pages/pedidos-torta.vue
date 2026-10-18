<template>
  <div>
    <h1>Pedidos de torta</h1>
    <p class="subtitulo">
      El encargo con fecha que hay que agendar. Es la venta de mayor valor del
      negocio y la que se pierde si no queda anotada.
    </p>

    <p v-if="cargando" class="vacio">Cargando...</p>

    <template v-else>
      <section v-if="agenda.atrasados.length > 0">
        <h2>Atrasados</h2>

        <p class="aviso aviso--alerta">
          Estos pedidos ya pasaron su fecha y no se han entregado. Siguen
          vigentes: el sistema no los marca como perdidos porque eso todavia no
          se sabe.
        </p>

        <TablaPedidos :pedidos="agenda.atrasados" />
      </section>

      <section>
        <h2>Para hoy</h2>
        <p v-if="agenda.hoy.length === 0" class="vacio">No hay entregas hoy.</p>
        <TablaPedidos v-else :pedidos="agenda.hoy" />
      </section>

      <section>
        <h2>Proximos</h2>
        <p v-if="agenda.proximos.length === 0" class="vacio">No hay pedidos agendados.</p>
        <TablaPedidos v-else :pedidos="agenda.proximos" />
      </section>

      <section>
        <h2>Agendar un pedido</h2>

        <div class="tarjeta">
          <form class="rejilla" @submit.prevent="agendar">
            <div class="grupo">
              <label class="etiqueta" for="cliente">Nombre del cliente</label>
              <input id="cliente" v-model="formulario.cliente_nombre" class="campo" required />
            </div>

            <div class="grupo">
              <label class="etiqueta" for="contacto">Contacto</label>
              <input
                id="contacto"
                v-model="formulario.cliente_contacto"
                class="campo"
                placeholder="Telefono o correo"
              />
              <span class="tenue">
                Es el unico contacto. Sin el, no hay manera de avisar que la
                torta salio antes de tiempo.
              </span>
            </div>

            <div class="grupo">
              <label class="etiqueta" for="producto">Torta</label>
              <select id="producto" v-model="formulario.articulo_id" class="campo" required>
                <option value="">Elige un producto</option>
                <option v-for="articulo in articulos" :key="articulo.id" :value="articulo.id">
                  {{ articulo.nombre }}
                </option>
              </select>
            </div>

            <div class="grupo">
              <label class="etiqueta" for="cantidad">Cuantas</label>
              <input
                id="cantidad"
                v-model.number="formulario.cantidad"
                class="campo"
                type="number"
                min="1"
                step="1"
              />
            </div>

            <div class="grupo">
              <label class="etiqueta" for="tamano">Tamano</label>
              <input id="tamano" v-model="formulario.tamano" class="campo" placeholder="12 pulgadas" />
            </div>

            <div class="grupo">
              <label class="etiqueta" for="sabor">Sabor</label>
              <input id="sabor" v-model="formulario.sabor" class="campo" />
            </div>

            <div class="grupo">
              <label class="etiqueta" for="fecha">Fecha de entrega</label>
              <input
                id="fecha"
                v-model="formulario.fecha_entrega"
                class="campo"
                type="date"
                required
              />
            </div>

            <div class="grupo">
              <label class="etiqueta" for="hora">Hora</label>
              <input id="hora" v-model="formulario.hora_entrega" class="campo" placeholder="16:30" />
            </div>

            <div class="grupo">
              <label class="etiqueta" for="monto">Monto total</label>
              <input
                id="monto"
                v-model.number="formulario.monto_total"
                class="campo"
                type="number"
                min="0"
                step="any"
                required
              />
              <span class="tenue">
                El sistema no tiene precios de torta cargados: el monto lo
                escribe quien toma el pedido.
              </span>
            </div>

            <div class="grupo">
              <label class="etiqueta" for="anticipo">Anticipo</label>
              <input
                id="anticipo"
                v-model.number="formulario.anticipo"
                class="campo"
                type="number"
                min="0"
                step="any"
              />
              <span class="tenue">
                Sin anticipo el pedido no se puede confirmar. Es la unica
                proteccion contra una torta que se produce y no se cobra.
              </span>
            </div>

            <div class="grupo">
              <label class="etiqueta" for="diseno">Diseno</label>
              <textarea id="diseno" v-model="formulario.diseno" class="campo" rows="3" />
              <span class="tenue">
                En texto. Subir imagenes queda pendiente: habria que decidir el
                almacenamiento y el respaldo de archivos.
              </span>
            </div>

            <div class="grupo">
              <button type="submit" class="boton boton--principal" :disabled="enviando">
                {{ enviando ? 'Agendando...' : 'Agendar pedido' }}
              </button>
            </div>
          </form>
        </div>

        <p v-if="error" class="aviso aviso--error">{{ error }}</p>
        <p v-if="exito" class="aviso aviso--exito">{{ exito }}</p>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import TablaPedidos from '~/components/TablaPedidos.vue';
import type { Agenda, Articulo } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const cargando = ref(true);
const enviando = ref(false);
const error = ref<string | null>(null);
const exito = ref<string | null>(null);

const agenda = ref<Agenda>({ hoy: [], proximos: [], atrasados: [] });
const articulos = ref<Articulo[]>([]);

const formulario = reactive({
  cliente_nombre: '',
  cliente_contacto: '',
  articulo_id: '',
  cantidad: 1,
  tamano: '',
  sabor: '',
  fecha_entrega: '',
  hora_entrega: '',
  monto_total: 0,
  anticipo: 0,
  diseno: '',
});

async function cargar(): Promise<void> {
  const [respuestaAgenda, respuestaArticulos] = await Promise.all([
    $fetch<Agenda>('/api/v1/pedidos-torta/agenda'),
    $fetch<{ articulos: Articulo[] }>('/api/v1/articulos?solo_vendibles=1'),
  ]);

  agenda.value = respuestaAgenda;
  articulos.value = respuestaArticulos.articulos;
}

onMounted(async () => {
  await cargar();
  cargando.value = false;
});

async function agendar(): Promise<void> {
  enviando.value = true;
  error.value = null;
  exito.value = null;

  try {
    const pedido = await $fetch<{ numero: string }>('/api/v1/pedidos-torta', {
      method: 'POST',
      body: {
        cliente_nombre: formulario.cliente_nombre,
        cliente_contacto: formulario.cliente_contacto || null,
        articulo_id: formulario.articulo_id,
        cantidad: formulario.cantidad,
        tamano: formulario.tamano || null,
        sabor: formulario.sabor || null,
        diseno: formulario.diseno || null,
        fecha_entrega: formulario.fecha_entrega,
        hora_entrega: formulario.hora_entrega || null,
        monto_total: formulario.monto_total,
        anticipo: formulario.anticipo,
      },
    });

    exito.value = `Pedido ${pedido.numero} agendado. Queda en borrador hasta que se confirme.`;

    await cargar();
  } catch (fallo) {
    error.value = (fallo as { data?: { mensaje?: string } })?.data?.mensaje
      ?? 'No se pudo agendar el pedido.';
  } finally {
    enviando.value = false;
  }
}

useHead({ title: 'Pedidos de torta · Gestion de Panaderia' });
</script>