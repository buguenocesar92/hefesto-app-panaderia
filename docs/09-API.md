# 09. API

Todas las rutas responden JSON. Todas viven bajo `/api/v1`.

## Convenciones

| | |
|---|---|
| Formato | JSON, siempre |
| Fecha | `AAAA-MM-DD` |
| Fecha y hora | ISO 8601 |
| Cantidad | Número decimal. Las unidades se guardan con 6 decimales y los pesos con 2. |
| Identificadores | UUID |

### Que la API responde con 404 en vez de 403

Cuando una Policy rechaza, la respuesta es **404**, no 403. Un 403 confirmaria
que el recurso existe, y eso ya es filtrar información de otra panaderia. Ver
[07-SEGURIDAD.md](07-SEGURIDAD.md).

## Los códigos de respuesta

| Código | Cuando |
|---|---|
| 200 | Todo bien |
| 201 | Se creo algo |
| 401 | No hay sesión, o se vencio |
| 404 | No existe, **o no puedes verlo**. Los dos casos son indistinguibles a propósito. |
| 422 | Error del negocio: no hay insumos, el lote esta vencido, el motivo falta, el estado no permite el paso. Trae `mensaje` y `codigo`. |
| 502 | No se pudo comunicar con el servicio de adentro. Solo lo ve quien administra. |

### Los errores del negocio

Los errores de la panaderia no son errores del sistema: son situaciones que hay
que resolver. Por eso se devuelven como **422 con un código estable**, para que
la interfaz reaccione sin adivinar el texto.

```json
{
  "mensaje": "No hay stock suficiente de Harina para esa tanda. Falta: Harina (falta 5000 g).",
  "codigo": "sin_insumos"
}
```

| `codigo` | Que paso |
|---|---|
| `sin_insumos` | No hay stock para la tanda. **No se toco nada.** |
| `lote_vencido` | El lote vencio y no se puede vender. |
| `lote_agotado` | El lote no tiene unidades. |
| `sin_stock` | No hay stock del producto para la cantidad pedida. |
| `sin_precio` | El articulo no tiene precio. No se puede cobrar. |
| `articulo_no_vendible` | El articulo no esta habilitado para el mostrador. |
| `venta_sin_items` | Una venta tiene que tener al menos un producto. |
| `venta_abierta_demasiado_tiempo` | La venta lleva mas de 10 minutos abierta. |
| `efectivo_insuficiente` | El efectivo entregado es menor que el total. |
| `merma_sin_motivo` | Una merma tiene que decir por que. |
| `motivo_inactivo` | El motivo de merma esta dado de baja. |
| `cantidad_invalida` | La cantidad es cero, negativa o no es un número. |
| `estado_invalido` | No se puede pasar de un estado a otro. |
| `pedido_sin_anticipo` | Un pedido no se confirma sin anticipo. |
| `pedido_con_anticipo` | Hay que devolver el anticipo antes de anular. |
| `pedido_ya_despachado` | El pedido ya tiene un despacho. |
| `anticipo_mayor_que_total` | El anticipo supera el total. |
| `numero_tandas_invalido` | El número de tandas es menor que 1. |
| `insumo_inexistente` | La receta apunta a un insumo que ya no existe. |
| `receta_sin_ingredientes` | Una receta vacia no puede producir. |
| `sin_regla_de_vencimiento` | El producto no tiene regla de vencimiento. |
| `sin_empresa` | No se sabe a que panaderia pertenece la operación. |
| `tanda_ya_terminada` | Una tanda terminada no se anula: se merma su lote. |

## Públicas

Sin sesión. Son las unicas tres.

### `GET /api/v1/salud`

```json
{ "estado": "ok", "base": true }
```

Devuelve **503** si la base no responde. No dice cuantos articulos hay ni que
versión corre.

### `GET /api/v1/panaderias`

El selector del acceso. **Es donde se usa la vista `vw_empresas_publicas`.**

```json
{
  "panaderias": [
    { "slug": "panaderia-ejemplo", "nombre": "Panaderia de Ejemplo" }
  ]
}
```

Solo panaderias activas, solo tres columnas. El `slug` que devuelve viaja en la
sesión y es lo que resuelve a que panaderia pertenece la cuenta.

### `GET /api/v1/acceso?empresa=<slug>`

Redirige al proveedor de identidad. Sin `empresa`, redirige a la pantalla de
entrada con un error.

### `GET /api/v1/acceso/callback`

Vuelve del proveedor. Valida el `state`, canjea el código, verifica el token e
inicia sesión.

## Con sesión

### Sesión

| | |
|---|---|
| `GET /api/v1/yo` | Quien soy y que puede hacer |
| `POST /api/v1/salir` | Cerrar sesión |
| `GET /api/v1/yo/diagnostico` | Si el aislamiento esta puesto |

`GET /api/v1/yo`:

```json
{
  "id": "...",
  "email": "duena@panaderia.cl",
  "nombre": "Duena",
  "rol": "encargado",
  "panaderia": "Mi Panaderia",
  "puede_producir": true,
  "puede_vender": true,
  "puede_registrar_merma": true,
  "puede_ver_reportes_merma": true,
  "puede_anular": true,
  "puede_cerrar_dia": true,
  "puede_administrar": false
}
```

Los `puede_*` son para mostrar el menu. **La autorización real es del servidor.**

`GET /api/v1/yo/diagnostico` dice si leer sin contexto fue rechazado. Ese
chequeo borra la marca antes de preguntar, porque si no, devolveria "todo bien"
sin haber comprobado nada.

### Articulos

| | |
|---|---|
| `GET /api/v1/articulos` | Listado. `?q=`, `?solo_vendibles=1`, `?incluir_inactivos=1` |
| `POST /api/v1/articulos` | Crear |
| `GET /api/v1/articulos/bajo-minimo` | Los que estan en o por debajo del minimo |
| `GET /api/v1/articulos/{id}` | Uno |
| `PUT /api/v1/articulos/{id}` | Editar |
| `POST /api/v1/articulos/{id}/desactivar` | Dar de baja. No se borra. |
| `GET /api/v1/articulos/{id}/movimientos` | El libro de movimientos |

El saldo de un articulo viene ledido del libro, no de una columna.

### Recetas

| | |
|---|---|
| `GET /api/v1/recetas` | Listado |
| `POST /api/v1/recetas` | Crear con sus ingredientes |
| `GET /api/v1/recetas/{id}` | Una, con sus ingredientes |
| `PUT /api/v1/recetas/{id}` | Editar |
| `GET /api/v1/recetas/{id}/rendimiento?tandas=3` | El calculo de rendimiento |

La respuesta del rendimiento:

```json
{
  "rendimiento": {
    "receta_id": "...",
    "numero_tandas": 3,
    "rendimiento_tanda": 40,
    "unidad_rendimiento": "UN",
    "rendimiento_total": 120,
    "insumos": [
      {
        "nombre": "Harina",
        "cantidad_por_tanda": 1050,
        "merma_porcentaje": 5,
        "cantidad_total": 3150,
        "stock_disponible": 15000,
        "alcanza": true
      }
    ],
    "alcanza": true
  },
  "faltantes": [],
  "costo": null,
  "aviso_costo": "No se calcula costo de produccion: falta el precio de costo de los insumos."
}
```

`costo` viene en `null` a propósito. No hay precio de costo de los insumos, y
usar el precio de venta daria una cifra inventada.

### Producción

| | |
|---|---|
| `GET /api/v1/tandas` | Listado. `?fecha=`, `?estado=` |
| `GET /api/v1/tandas/planificadas` | Las que faltan por registrar |
| `POST /api/v1/tandas` | Agendar una corrida |
| `GET /api/v1/tandas/{id}` | Una, con sus lotes |
| `POST /api/v1/tandas/{id}/registrar` | Registrar que salio del horno |
| `POST /api/v1/tandas/{id}/sobrante` | Cerrar el turno |
| `POST /api/v1/tandas/{id}/anular` | Anular |

`registrar`:

```json
{
  "cantidad_producida": 118,
  "ubicacion_id": "...",
  "numero_tandas": 3
}
```

`sobrante`:

```json
{
  "bodega_id": "..."
}
```

```json
{
  "traspasados": [{ "codigo": "LOTE-TA-...", "cantidad": 14, "a": "Bodega" }],
  "mermados": [],
  "total_sobrante": 14
}
```

Anular una tanda que **ya termino** devuelve `tanda_ya_terminada`: ya movio
inventario, y lo que corresponde es merma del lote, no anular.

### Merma

| | |
|---|---|
| `GET /api/v1/mermas` | Listado. `?desde=`, `?hasta=`, `?articulo_id=` |
| `POST /api/v1/mermas` | Registrar una perdida |
| `GET /api/v1/mermas/motivos` | Los motivos disponibles |

`motivo_merma_id` es obligatorio y no admite `NULL`. Sin el, la perdida queda
escrita pero no explicada, que es lo mismo que no registrarla.

### Mostrador

| | |
|---|---|
| `GET /api/v1/ventas` | Listado. `?fecha=`, `?estado=` |
| `POST /api/v1/ventas` | Abrir una venta |
| `GET /api/v1/ventas/{id}` | Una, con sus lineas y pagos |
| `POST /api/v1/ventas/{id}/pagar` | Cobrar y cerrar |
| `POST /api/v1/ventas/{id}/anular` | Anular una venta abierta |

La venta se **abre** y despues se **cobra**. El stock se descuenta al abrir,
porque el pan ya salio de la vitrina aunque el pago este pendiente.

Al abrir:

```json
{
  "items": [{ "articulo_id": "...", "cantidad": 6 }],
  "lote_cierre_id": "...",
  "notas": null
}
```

Al pagar:

```json
{ "metodo": "efectivo", "recibido": 5000 }
```

Una venta **cerrada** (ya cobrada) no se anula desde aca: se devuelve el dinero
y se registra el retiro.

### Lotes de cierre

| | |
|---|---|
| `GET /api/v1/lotes-cierre` | Listado |
| `POST /api/v1/lotes-cierre` | Abrir uno |
| `GET /api/v1/lotes-cierre/{id}` | Uno, con sus ventas y totales |
| `POST /api/v1/lotes-cierre/{id}/cerrar` | Cerrar la caja del dia |

Al cerrar devuelve cuantas ventas quedaron abiertas, para que se anulen antes
de levantar la caja.

### Pedidos de torta

| | |
|---|---|
| `GET /api/v1/pedidos-torta` | Listado |
| `GET /api/v1/pedidos-torta/agenda` | Hoy, lo que viene y lo atrasado |
| `POST /api/v1/pedidos-torta` | Agendar |
| `GET /api/v1/pedidos-torta/{id}` | Uno |
| `PUT /api/v1/pedidos-torta/{id}` | Editar (solo si admite cambios) |
| `POST /api/v1/pedidos-torta/{id}/confirmar` | Confirmar. **Exige anticipo.** |
| `POST /api/v1/pedidos-torta/{id}/anticipo` | Sumar anticipo |
| `POST /api/v1/pedidos-torta/{id}/despachar` | Despachar |
| `POST /api/v1/pedidos-torta/{id}/anular` | Anular |

La agenda separa los atrasados:

```json
{
  "hoy": [],
  "proximos": [],
  "atrasados": [
    { "numero": "T-20260308-0001", "cliente_nombre": "Cliente", "atrasado": true, "saldo": 45000 }
  ]
}
```

Un pedido atrasado **no se marca como perdido**: sigue vigente. Marcarlo
perdido automáticamente seria inventar un hecho que todavia no se sabe.

### Inventario

| | |
|---|---|
| `GET /api/v1/inventario` | Saldos de todos los articulos |
| `GET /api/v1/inventario/lotes` | Lotes con stock. `?solo_disponibles=1` |
| `GET /api/v1/inventario/lotes/{id}` | Un lote con su historial de movimientos |
| `GET /api/v1/inventario/caducando` | Por vencer y vencidos |
| `POST /api/v1/inventario/recepcion` | Compra |

Los lotes vienen ordenados por `fecha_vencimiento ASC NULLS LAST`: primero el
que vence antes, y al final lo que no caduca. Sin ese `NULLS LAST`,
PostgreSQL pondria los nulos primero y el pan sin regla se venderia siempre
primero.

### Reportes de merma

| | |
|---|---|
| `GET /api/v1/reportes/merma` | Indice |
| `GET /api/v1/reportes/merma/por-motivo` | Agrupado por motivo |
| `GET /api/v1/reportes/merma/por-producto` | Agrupado por producto |

Por omision, el mes en curso. Con `?desde=` y `?hasta=`, ese rango.

Separan `manual` de `automatica`: la perdida que vino del reloj es distinta de
la que vino de las manos.

`valor_perdido` viene en `null` cuando el articulo no tiene precio. Un `0`
afirmaria que no se perdio nada.

### Configuración

| | |
|---|---|
| `GET /api/v1/configuracion` | Resumen, y cuanto falta configurar |
| `GET /api/v1/configuracion/vigencias` | Las reglas por producto |
| `POST /api/v1/configuracion/vigencias` | Guardar una regla |
| `GET /api/v1/configuracion/motivos-merma` | Los motivos |
| `POST /api/v1/configuracion/motivos-merma` | Guardar un motivo |
| `GET /api/v1/configuracion/unidades` | Las unidades |
| `POST /api/v1/configuracion/unidades` | Guardar una unidad |
| `GET /api/v1/configuracion/ubicaciones` | Las ubicaciones |
| `POST /api/v1/configuracion/ubicaciones` | Guardar una ubicacion |
| `GET /api/v1/configuracion/personas` | Las personas con su rol |
| `POST /api/v1/configuracion/personas/{id}/rol` | Cambiar el rol |

Guardar una vigencia:

```json
{
  "articulo_id": "...",
  "dias_vigencia": 1,
  "merma_automatica": true
}
```

`dias_vigencia` acepta `null`, y eso significa "este producto no caduca".
`0` significa "caduca hoy": son cosas distintas.

Cambiar el rol de alguien **no** se puede hacer sobre si mismo. Sin ese
control, el único administrador podria degradarse y dejar el sistema sin nadie
que lo administre.

## La documentacion automática

**No existe.** No hay un endpoint que genere una especificacion OpenAPI.

Los tipos de `web/app/tipos/api.ts` estan escritos a mano. Cuando exista la
generación automática, se reemplazan por los generados. Ver el comentario en
ese archivo.

## Lo que no hay en la API

| Falta | Por que |
|---|---|
| Costo de producción | No hay precio de costo de los insumos. Ver [02](02-MODELO-DE-DATOS.md). |
| Borrar un articulo | Un articulo con ventas encima no se borra: se da de baja. Borrar perderia la historia. |
| Editar un movimiento | Un movimiento es historico. Para corregirlo se registra el contrario. |
| Editar una receta que ya produjo | Las recetas tienen versión. Cambiar una no reescribe las tandas viejas. |
| Anular una venta cobrada | Se devuelve el dinero y se registra el retiro. Si se pudiera anular, se podria borrar del historico una venta que si entrego mercaderia. |