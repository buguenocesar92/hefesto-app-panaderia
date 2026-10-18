<template>
  <div>
    <h1>Panaderia</h1>
    <p class="subtitulo">{{ panaderia ?? '...' }}</p>

    <section v-if="cargando" class="vacio">Cargando...</section>

    <template v-else>
      <!--
          LA TARJETA DE CADUCIDAD VA PRIMERO, A PROPOSITO.
          El problema de una panaderia no es producir: es que lo que produjo
          se queda parado hasta que pasa su fecha. Por eso la pantalla de
          entrada empieza por lo que hay que mirar hoy.
      -->
      <section class="homenaje">
        <h2>Que hay que mirar hoy</h2>

        <div class="rejilla">
          <NuxtLink to="/inventario?filtro=caducando" class="tarjeta-resumen">
            <span class="tarjeta-resumen__cifra">{{ caducidad.vencidos.length }}</span>
            <span class="tarjeta-resumen__texto">lotes vencidos</span>
            <span class="tenue">No se pueden vender. Hay que decidir.</span>
          </NuxtLink>

          <NuxtLink to="/inventario?filtro=caducando" class="tarjeta-resumen">
            <span class="tarjeta-resumen__cifra">{{ caducidad.por_vencer.length }}</span>
            <span class="tarjeta-resumen__texto">lotes por vencer</span>
            <span class="tenue">
              En los proximos {{ configuracion?.dias_aviso_caducidad ?? 1 }} dia(s).
            </span>
          </NuxtLink>

          <NuxtLink to="/pedidos-torta" class="tarjeta-resumen">
            <span class="tarjeta-resumen__cifra">{{ agenda.hoy.length + agenda.atrasados.length }}</span>
            <span class="tarjeta-resumen__texto">torta(s) para hoy o atrasadas</span>
            <span class="tenue">La venta de mayor valor del negocio.</span>
          </NuxtLink>

          <NuxtLink v-if="puedeProducir" to="/produccion" class="tarjeta-resumen">
            <span class="tarjeta-resumen__cifra">{{ tandasPendientes.length }}</span>
            <span class="tarjeta-resumen__texto">tandas por registrar</span>
            <span class="tenue">Corridas que todavia no produjeron.</span>
          </NuxtLink>
        </div>
      </section>

      <!-- Los pedidos atrasados van juntos y arriba: son los que se pierden. -->
      <section v-if="agenda.atrasados.length > 0">
        <h2>Pedidos de torta atrasados</h2>

        <p class="aviso aviso--alerta">
          Estos {{ agenda.atrasados.length }} pedido(s) ya pasaron su fecha y no
          se han entregado. Siguen vigentes: el sistema no los marca como
          perdidos porque eso todavia no se sabe.
        </p>

        <ul class="lista-simple">
          <li v-for="pedido in agenda.atrasados" :key="pedido.id">
            <strong>{{ pedido.numero }}</strong> — {{ pedido.cliente_nombre }},
            {{ pedido.cantidad }} x {{ pedido.articulo }}
            <span class="estado estado--atrasado">atrasado</span>
            <span v-if="pedido.saldo > 0" class="tenue"> saldo pendiente</span>
          </li>
        </ul>
      </section>

      <section>
        <h2>Lo que hay que mirar</h2>

        <ul class="lista-simple">
          <li>
            <NuxtLink to="/produccion">Produccion por tanda</NuxtLink>
            <span class="tenue">Registrar que salio del horno.</span>
          </li>
          <li>
            <NuxtLink to="/merma">Registrar merma</NuxtLink>
            <span class="tenue">Lo que se quemo, se cayo o vencio, con su motivo.</span>
          </li>
          <li>
            <NuxtLink to="/mostrador">Mostrador</NuxtLink>
            <span class="tenue">Venta rapida, sin fila.</span>
          </li>
          <li>
            <NuxtLink to="/pedidos-torta">Pedidos de torta</NuxtLink>
            <span class="tenue">La agenda del dia.</span>
          </li>
          <li v-if="puedeVerReportes">
            <NuxtLink to="/reportes/merma">Reportes de merma</NuxtLink>
            <span class="tenue">Cuanto se perdio, y en que.</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import type { Agenda, ResumenDeConfiguracion, RevisionDeCaducidad, Tanda } from '~/tipos/api';

definePageMeta({ middleware: 'auth' });

const { usuario, puedeProducir, puedeVerReportes } = useSesion();

const cargando = ref(true);

const caducidad = ref<RevisionDeCaducidad>({ por_vencer: [], vencidos: [] });
const agenda = ref<Agenda>({ hoy: [], proximos: [], atrasados: [] });
const tandasPendientes = ref<Tanda[]>([]);
const configuracion = ref<ResumenDeConfiguracion | null>(null);

const panaderia = computed(() => usuario.value?.panaderia ?? null);

onMounted(async () => {
  // Cada dato se pide por separado y con manejo de error individual: que
  // falle la agenda no puede dejar en blanco la caducidad, que es lo que de
  // verdad hay que mirar hoy.
  const resultados = await Promise.allSettled([
    $fetch<RevisionDeCaducidad>('/api/v1/inventario/caducando'),
    $fetch<Agenda>('/api/v1/pedidos-torta/agenda'),
    $fetch<{ tandas: Tanda[] }>('/api/v1/tandas/planificadas'),
    $fetch<ResumenDeConfiguracion>('/api/v1/configuracion'),
  ]);

  if (resultados[0].status === 'fulfilled') {
    caducidad.value = resultados[0].value;
  }

  if (resultados[1].status === 'fulfilled') {
    agenda.value = resultados[1].value;
  }

  if (resultados[2].status === 'fulfilled') {
    tandasPendientes.value = resultados[2].value.tandas;
  }

  if (resultados[3].status === 'fulfilled') {
    configuracion.value = resultados[3].value;
  }

  cargando.value = false;
});

useHead({ title: 'Panaderia · Gestion de Panaderia' });
</script>

<style scoped>
.homenaje {
  margin-bottom: 1.5rem;
}

.tarjeta-resumen {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 1rem;
  background: #fff;
  border: 1px solid #cbd2d9;
  border-radius: 0.375rem;
  text-decoration: none;
  color: inherit;
}

.tarjeta-resumen:hover {
  border-color: #1b4d3e;
}

.tarjeta-resumen__cifra {
  font-size: 2rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1.1;
}

.tarjeta-resumen__texto {
  font-weight: 600;
}

.lista-simple {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 0.4rem;
}

.lista-simple li {
  padding: 0.5rem 0;
  border-bottom: 1px solid #cbd2d9;
}
</style>