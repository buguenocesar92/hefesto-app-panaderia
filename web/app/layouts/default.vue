<template>
  <div class="marco">
    <header class="barra">
      <NuxtLink to="/" class="barra__marca">
        Gestion de Panaderia
      </NuxtLink>

      <nav v-if="quien" class="barra__menu">
        <NuxtLink to="/" class="barra__enlace">Inicio</NuxtLink>
        <NuxtLink to="/produccion" class="barra__enlace">Produccion</NuxtLink>
        <NuxtLink to="/recetas" class="barra__enlace">Recetas</NuxtLink>
        <NuxtLink to="/merma" class="barra__enlace">Merma</NuxtLink>
        <NuxtLink to="/mostrador" class="barra__enlace">Mostrador</NuxtLink>
        <NuxtLink to="/pedidos-torta" class="barra__enlace">Pedidos</NuxtLink>
        <NuxtLink to="/inventario" class="barra__enlace">Inventario</NuxtLink>

        <!--
            El reporte de merma dice cuanto dinero perdio el negocio. No es un
            dato de todos: el rol decide si se muestra. La API igual lo
            rechaza si se pide sin permiso.
        -->
        <NuxtLink v-if="puedeVerReportes" to="/reportes/merma" class="barra__enlace">
          Reportes
        </NuxtLink>

        <NuxtLink v-if="esAdministrador" to="/configuracion" class="barra__enlace">
          Configuracion
        </NuxtLink>
      </nav>

      <div v-if="quien" class="barra__sesion">
        <span class="barra__persona">{{ quien.nombre }}</span>
        <button type="button" class="boton boton--chico" @click="salir()">Salir</button>
      </div>
    </header>

    <main class="contenido">
      <slot />
    </main>
  </div>
</template>

<script setup lang="ts">
const { usuario, salir, esAdministrador, puedeVerReportes } = useSesion();

const quien = computed(() => usuario.value);
</script>