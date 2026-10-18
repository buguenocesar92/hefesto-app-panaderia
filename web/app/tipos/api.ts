// ---------------------------------------------------------------------------
// Tipos que viajan entre la interfaz y la API.
// ---------------------------------------------------------------------------
//
// Son una COPIA de lo que devuelve la API, escrita a mano. Podrian
// generarse desde OpenAPI, pero hoy no hay ningun endpoint que lo exponga, y
// inventar el comando "para generarlo despues" seria prometer algo que no
// existe. Cuando exista, estos tipos se reemplazan por los generados y esta
// nota se borra.
//
// La regla: si un tipo y la API no coinciden, el problema esta en el tipo.
// Ver docs/09-API.md.
// ---------------------------------------------------------------------------

/** Quien esta operando. Lo devuelve `GET /api/v1/yo`. */
export interface Usuario {
  id: string;
  email: string;
  nombre: string;
  rol: 'auditor' | 'mostrador' | 'horno' | 'encargado' | 'administrador';
  panaderia?: string | null;

  puede_producir: boolean;
  puede_vender: boolean;
  puede_registrar_merma: boolean;
  puede_ver_reportes_merma: boolean;
  puede_anular: boolean;
  puede_cerrar_dia: boolean;
  puede_administrar: boolean;
}

/** Una panaderia del selector de acceso. */
export interface PanaderiaPublica {
  slug: string;
  nombre: string;
}

/** Articulo del inventario. */
export interface Articulo {
  id: string;
  sku: string;
  nombre: string;
  descripcion: string | null;

  unidad_base: string | null;

  /** NULL cuando el articulo no tiene precio cargado. */
  precio_venta: number | null;
  /** FALSE significa que no se puede cobrar en el mostrador. */
  tiene_precio: boolean;

  stock_minimo: number | null;
  saldo: number;

  se_vende: boolean;
  es_insumo: boolean;
  activo: boolean;

  /** NULL cuando el producto no tiene regla de vencimiento. */
  dias_vigencia: number | null;
  tiene_regla_vigencia: boolean;
}

/** Una receta y su rendimiento. */
export interface Receta {
  id: string;
  nombre: string;
  version: number;
  articulo: string | null;
  articulo_id: string;
  rendimiento_cantidad: number;
  rendimiento_unidad: string | null;
  activa: boolean;
  notas: string | null;
  ingredientes: IngredienteDeReceta[];
}

export interface IngredienteDeReceta {
  insumo_id: string;
  nombre: string | null;
  cantidad: number;
  unidad_id: string;
  merma_porcentaje: number;
  /** Lo que hay que sacar del almacen, ya con la merma estimada. */
  cantidad_total: number;
}

/** Lo que sale de una tanda, y lo que hay que gastar para conseguirla. */
export interface Rendimiento {
  receta_id: string;
  numero_tandas: number;
  rendimiento_tanda: number;
  unidad_rendimiento: string;
  rendimiento_total: number;
  insumos: InsumoRequerido[];
  /** FALSE si a algun insumo no le alcanza. */
  alcanza: boolean;
}

export interface InsumoRequerido {
  insumo_id: string;
  nombre: string;
  unidad: string;
  cantidad_por_tanda: number;
  merma_porcentaje: number;
  cantidad_total: number;
  stock_disponible: number;
  alcanza: boolean;
}

export interface Faltante {
  nombre: string;
  falta: number;
  unidad: string;
}

/** Una corrida de produccion. */
export interface Tanda {
  id: string;
  numero: string;
  receta: string | null;
  receta_id: string;
  fecha: string | null;
  /** Lo que se quiso hacer. */
  cantidad_planificada: number | null;
  /** Lo que realmente salio del horno. */
  cantidad_producida: number | null;
  estado: 'planificada' | 'en_curso' | 'terminada' | 'anulada';
  hornero: string | null;
  lotes: LoteDeTanda[];
  notas: string | null;
}

export interface LoteDeTanda {
  codigo: string;
  cantidad_disponible: number;
  estado: string;
}

/** Un lote de mercaderia, con su fecha. */
export interface Lote {
  id: string;
  codigo: string;
  articulo: string | null;
  ubicacion: string | null;
  tipo_ubicacion: string | null;
  cantidad_inicial: number;
  cantidad_disponible: number;
  fecha_produccion: string | null;
  fecha_vencimiento: string | null;
  dias_para_vencer: number | null;
  estado: 'disponible' | 'agotado' | 'vencido' | 'mermado';
  de_tanda: boolean;
}

/** Una perdida de mercaderia. */
export interface Merma {
  id: string;
  articulo: string | null;
  lote: string | null;
  motivo: string | null;
  cantidad: number;
  /** NULL cuando el articulo no tiene precio: no se puede saber cuanto dinero se perdio. */
  valor_perdido: number | null;
  fecha: string | null;
  origen: 'manual' | 'automatica';
  usuario: string | null;
  notas: string | null;
}

export interface MotivoMerma {
  id: string;
  codigo: string;
  nombre: string;
  descripcion: string | null;
  merma_por_vencimiento: boolean;
}

/** Una venta de mostrador. */
export interface Venta {
  id: string;
  numero: string;
  estado: 'abierta' | 'cerrada' | 'anulada';
  fecha: string | null;
  bruto: number;
  descuento: number;
  total: number;
  lote_cierre_id: string | null;
  minutos_abierta: number;
  items: ItemDeVenta[];
  pagos: Pago[];
}

export interface ItemDeVenta {
  articulo: string | null;
  lote: string | null;
  cantidad: number;
  precio_unitario: number;
  subtotal: number;
}

export interface Pago {
  metodo: 'efectivo' | 'debito' | 'credito' | 'transferencia';
  monto: number;
  recibido: number | null;
  vuelto: number | null;
}

/** Un lote de cierre: el grupo de ventas con descuento. */
export interface LoteCierre {
  id: string;
  nombre: string;
  fecha: string | null;
  descuento_porcentaje: number;
  estado: 'abierto' | 'cerrado';
  cerrado_en: string | null;
}

/** Un encargo de torta. */
export interface PedidoTorta {
  id: string;
  numero: string;
  cliente_nombre: string;
  cliente_contacto: string | null;
  articulo: string | null;
  cantidad: number;
  tamano: string | null;
  sabor: string | null;
  diseno: string | null;
  fecha_entrega: string | null;
  hora_entrega: string | null;
  estado: 'borrador' | 'confirmado' | 'en_produccion' | 'listo' | 'entregado' | 'anulado';
  monto_total: number;
  anticipo: number;
  saldo: number;
  /** Pedido cuya fecha ya paso y que todavia no se entrego. */
  atrasado: boolean;
  despachado: boolean;
}

export interface FilaDeAgenda {
  id: string;
  numero: string;
  cliente_nombre: string;
  articulo: string | null;
  cantidad: number;
  tamano: string | null;
  hora_entrega: string | null;
  estado: string;
  saldo: number;
  atrasado: boolean;
}

export interface Agenda {
  hoy: FilaDeAgenda[];
  proximos: FilaDeAgenda[];
  atrasados: FilaDeAgenda[];
}

/** Lo que se esta por vencer y lo que ya vencio. */
export interface RevisionDeCaducidad {
  por_vencer: Array<{
    codigo: string;
    articulo: string | null;
    cantidad_disponible: number;
    fecha_vencimiento: string | null;
    dias: number | null;
  }>;
  vencidos: Array<{
    codigo: string;
    articulo: string | null;
    cantidad_disponible: number;
    fecha_vencimiento: string | null;
    dias: number | null;
  }>;
}

/** El resumen de la configuracion. */
export interface ResumenDeConfiguracion {
  articulos_activos: number;
  articulos_sin_regla_vigencia: number;
  motivos_merma_activos: number;
  unidades: number;
  ubicaciones: number;
  /** NULL cuando no hay valor por omision: es una decision consciente. */
  vigencia_por_defecto_dias: number | null;
  dias_aviso_caducidad: number;
  minutos_maximos_venta: number;
}

/** Una regla de vencimiento. */
export interface VigenciaArticulo {
  articulo_id: string;
  sku: string;
  nombre: string;
  /** NULL significa "este producto no caduca". */
  dias_vigencia: number | null;
  merma_automatica: boolean;
  tiene_regla: boolean;
  /** Lo que se aplica de verdad. NULL = el lote saldra sin vencimiento. */
  regla_efectiva: number | null;
  notas: string | null;
}

/** Los saldos de todo el inventario. */
export interface SaldoDeArticulo {
  articulo_id: string;
  nombre: string;
  sku: string;
  saldo: number;
  stock_minimo: number | null;
  faltan?: number;
}