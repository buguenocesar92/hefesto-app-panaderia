// ---------------------------------------------------------------------------
// Sesion de la persona que esta operando la panaderia.
// ---------------------------------------------------------------------------
//
// El rol decide QUE SE MUESTRA en el menu, pero NO decide QUE SE PUEDE
// HACER: la autorizacion real la hace la API. Esto es solo para no mostrar
// botones que van a ser rechazados. Es una cortesia, no una proteccion.
//
// Ver docs/03-MULTITENANCY-Y-ROLES.md.
// ---------------------------------------------------------------------------

import type { Usuario } from '~/tipos/api';

export function useSesion() {
  const usuario = useState<Usuario | null>('sesion:usuario', () => null);
  const cargado = useState<boolean>('sesion:cargada', () => false);

  async function cargar(): Promise<Usuario | null> {
    if (cargado.value) {
      return usuario.value;
    }

    try {
      usuario.value = await $fetch<Usuario>('/api/v1/yo');
    } catch {
      usuario.value = null;
    }

    cargado.value = true;

    return usuario.value;
  }

  async function salir(): Promise<void> {
    await $fetch('/api/v1/salir', { method: 'POST' });

    usuario.value = null;
    cargado.value = true;

    await navigateTo('/entrar');
  }

  return {
    usuario,
    cargado,
    cargar,
    salir,

    esAdministrador: computed(() => usuario.value?.puede_administrar === true),

    /** Puede hornear y registrar tandas y merma de su turno. */
    puedeProducir: computed(
      () => usuario.value?.puede_producir === true || usuario.value?.puede_administrar === true,
    ),

    /** Puede vender en el mostrador. */
    puedeVender: computed(
      () => usuario.value?.puede_vender === true || usuario.value?.puede_administrar === true,
    ),

    /** Puede registrar merma. */
    puedeRegistrarMerma: computed(
      () =>
        usuario.value?.puede_registrar_merma === true || usuario.value?.puede_administrar === true,
    ),

    /** Puede ver los reportes de merma, que dicen cuanto dinero se perdio. */
    puedeVerReportes: computed(
      () =>
        usuario.value?.puede_ver_reportes_merma === true ||
        usuario.value?.puede_administrar === true,
    ),
  };
}