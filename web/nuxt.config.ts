// ---------------------------------------------------------------------------
// Configuracion de la capa web (Nuxt 4, salida en modo servidor)
// ---------------------------------------------------------------------------
//
// EL NAVEGADOR NO HABLA NUNCA CON LA API
// --------------------------------------
// Va a este contenedor, y este reenvia. Es lo que se llama servidor
// intermedio: asi la cookie de sesion viaja en el mismo origen y no hay que
// exponer la API al exterior ni configurar CORS para el navegador.
//
// Ver docs/01-ARQUITECTURA.md.
// ---------------------------------------------------------------------------

export default defineNuxtConfig({
  compatibilityDate: '2026-01-15',

  devtools: { enabled: false },

  // El render en el servidor es lo que permite que la cookie de sesion
  // llegue al navegador: si la aplicacion fuera solo cliente, el primer
  // HTML saldría sin sesion y habria que corregirlo despues.
  ssr: true,

  runtimeConfig: {
    // Solo el servidor. Son los valores de NUXT_API_BASE y NUXT_PUBLIC_URL.
    apiBase: 'http://api:8000',
    publicUrl: 'http://localhost:3000',

    // Con prefijo `public` estas SI van al navegador, porque por definicion
    // son publicas.
    public: {
      url: 'http://localhost:3000',
      apiBase: 'http://localhost:8000',
    },
  },

  css: ['~/assets/css/main.css'],

  typescript: {
    // El chequeo estricto esta Activado a proposito. Una aplicacion que
    // maneja dinero y stock no puede dejar valores sueltos de tipo `any`.
    strict: true,
  },

  app: {
    head: {
      htmlAttrs: { lang: 'es-CL' },
      title: 'Gestion de Panaderia',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'description', content: 'Produccion, merma, caducidad y mostrador de una panaderia.' },
      ],
    },
  },
});