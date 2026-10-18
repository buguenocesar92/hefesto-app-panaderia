<template>
  <section class="entrar">
    <h1>Gestion de Panaderia</h1>

    <p class="entrar__intro">
      Elige la panaderia con la que vas a trabajar y entra con tu cuenta
      corporativa. El rol que decides en que puedes trabajar se configura en
      el servidor de identidad.
    </p>

    <p v-if="error" class="aviso aviso--error">{{ mensajeDeError }}</p>

    <!--
        EL SELECTOR USA LA VISTA `vw_empresas_publicas`
        -----------------------------------------------
        Esta lista sale del endpoint `GET /api/v1/panaderias`, que lee la
        vista de TRES columnas. Es el unico lugar del sistema donde se leen
        datos de una panaderia sin identificarse, asi que la vista existe
        justamente para esto.

        Si la lista no aparece, NO se muestran todos los articulos ni un
        formulario generico: el acceso se detiene. Ver docs/03.
    -->
    <div class="entrar__lista">
      <h2>Panaderias</h2>

      <p v-if="cargando" class="tenue">Cargando...</p>

      <p v-else-if="cargaError" class="aviso aviso--error">
        No se pudo cargar la lista de panaderias. Avisa al administrador.
      </p>

      <p v-else-if="panaderias.length === 0" class="aviso aviso--alerta">
        No hay ninguna panaderia disponible. Avisa al administrador: puede que
        falte crear la primera.
      </p>

      <ul v-else class="panaderias">
        <li v-for="panaderia in panaderias" :key="panaderia.slug">
          <a class="boton boton--principal boton--ancho" :href="urlDeAcceso(panaderia.slug)">
            {{ panaderia.nombre }}
          </a>
        </li>
      </ul>
    </div>

    <p class="entrar__nota">
      El acceso se hace en el servidor de identidad de Hefesto Studio. Esta
      aplicacion no guarda contrasenas.
    </p>
  </section>
</template>

<script setup lang="ts">
import type { PanaderiaPublica } from '~/tipos/api';

const ruta = useRoute();

const cargando = ref(true);
const cargaError = ref(false);

const error = computed(() => {
  const valor = ruta.query.error;

  return typeof valor === 'string' ? valor : null;
});

/**
 * Los mensajes de error, en palabras y no en codigos.
 *
 * El texto que llega por la URL viene del servidor, asi que se muestra
 * tal cual: inventar una traduccion aca podria tapar un motivo real de
 * rechazo.
 */
const mensajeDeError = computed(() => {
  switch (error.value) {
    case 'elige_una_panaderia':
      return 'Primero elige la panaderia con la que vas a trabajar.';
    case 'panaderia_no_disponible':
      return 'Esa panaderia no esta disponible.';
    case 'state_invalido':
      return 'La solicitud de acceso expiro. Intenta nuevamente.';
    case 'cuenta_no_existe':
      return 'No tienes una cuenta en esa panaderia. Pide al encargado que te cree el acceso.';
    case 'cuenta_desactivada':
      return 'Tu cuenta esta desactivada.';
    case 'correo_no_verificado':
      return 'Tu correo no esta verificado en el servidor de identidad.';
    case 'varias_panaderias':
      return 'Tu correo tiene cuentas en varias panaderias. Elige una en la lista.';
    default:
      return error.value ?? 'No se pudo completar el acceso.';
  }
});

/**
 * La URL de acceso para una panaderia.
 *
 * Va al endpoint de la API, no a un proveedor de identidad externo: la API
 * es la que sabe que existe esa panaderia y la que arma la URL con el
 * `state` de proteccion.
 */
function urlDeAcceso(slug: string): string {
  return `/api/v1/acceso?empresa=${encodeURIComponent(slug)}`;
}

onMounted(async () => {
  try {
    const respuesta = await $fetch<{ panaderias: PanaderiaPublica[] }>('/api/v1/panaderias');

    cargando.value = false;
  } catch {
    cargaError.value = true;
    cargando.value = false;
  }
});

useHead({ title: 'Entrar · Gestion de Panaderia' });
</script>

<style scoped>
.entrar {
  max-width: 34rem;
  margin: 3rem auto;
}

.entrar__intro {
  color: #52606d;
  line-height: 1.6;
}

.entrar__lista {
  margin-top: 2rem;
}

.panaderias {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 0.5rem;
}

.boton--ancho {
  width: 100%;
}

.entrar__nota {
  margin-top: 2rem;
  font-size: 0.8rem;
  color: #7b8794;
}
</style>