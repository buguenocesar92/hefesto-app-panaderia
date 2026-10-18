# 02. Modelo de datos

## Las 19 tablas

18 llevan `tenant_id` y Row Level Security. `tenants` es la única que no, y el
motivo esta en [03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md).

| Tabla | Que guarda | Una fila es |
|---|---|---|
| `tenants` | Las panaderias usuarias | Una panaderia |
| `usuarios` | Quién opera y con que rol | Una persona |
| `unidades_medida` | Gramo, kilo, unidad | Una unidad |
| `articulos` | El catalogo maestro | Un articulo del inventario |
| `ubicaciones` | Bodega, mostrador, refrigeracion | Un lugar |
| `vigencias` | Cuanto caduca cada producto | La regla de un producto |
| `motivos_merma` | Por que se pierde mercaderia | Un motivo |
| `recetas` | Como se hace y CUANTO rinde | Una versión de receta |
| `receta_ingredientes` | Que lleva la receta | Un insumo dentro de una receta |
| `tandas` | Una corrida | Una corrida del horno |
| `lote_productos` | Mercaderia con fecha y origen | Un lote |
| `movimientos_inventario` | El libro de todo lo que se movio | Un movimiento |
| `mermas` | Perdidas, con su motivo | Una perdida |
| `lotes_cierre` | El grupo de ventas con descuento | Un lote de cierre |
| `ventas` | Venta de mostrador | Una venta |
| `venta_items` | Las lineas de la venta | Una linea |
| `pagos` | Como se cobro | Un pago |
| `pedidos_torta` | El encargo con fecha | Un pedido |
| `despachos` | La entrega | Un despacho |

## La decisión que sostiene el modelo: UN solo catalogo

En una panaderia, la harina usada en el pan y la bolsa de harina que se vende
en el mostrador son **el mismo articulo**, en el mismo almacen y con el mismo
número de stock.

Si estuvieran en dos tablas distintas, vender una bolsa restaria de un
inventario y gastar harina en una tanda restaria de otro. Los dos saldos
bajarian, pero ninguna merma se veria en ningun lado: el sistema diria que no
se perdio nada y los dos números habrian bajado por separado.

Por eso existe UNA tabla `articulos` con dos interruptores:

| Interruptor | Que dice | Ejemplo |
|---|---|---|
| `se_vende` | Aparece en el mostrador | Harina: `true`. Torta: `true`. |
| `es_insumo` | Se puede consumir en una receta | Harina: `true`. Torta: `false`. |

Ninguno de los dos es obligatorio. Un insumo que no se vende tiene
`se_vende = false`. Un producto que no se fabrica tiene `es_insumo = false`.

Y el `receta_ingredientes.insumo_id` apunta a `articulos`, no a otra tabla.

## Las unidades y por que el factor vive en la unidad

`unidades_medida.unidades_por_base` dice cuantas unidades base trae una de esa
unidad.

El factor esta en la UNIDAD y no en el articulo por una razon concreta: dos
articulos que se venden en bolsas de distinto peso necesitan dos unidades
distintas, aunque las dos se llamen "bolsa".

| Unidad | Código | Factor |
|---|---|---|
| Unidad | `UN` | 1 |
| Gramo | `G` | 1 |
| Kilo | `KG` | 1000 |

Una bolsa de 25 kilos seria una unidad con factor 25000.

## La vigencia: por que es una tabla y no un número en el articulo

"El pan caduca hoy, el sándwich mañana" no es una regla general: es una regla
**por producto**, y la fija la panaderia.

Poner el número en el articulo obligaria a reescribir el catalogo cada vez que
cambia el pan o el relleno, y no permitiria dejar un producto sin regla.

```
vigencias
  articulo_id       el producto
  dias_vigencia     NULL = este producto NO caduca
  merma_automatica  si el trabajo programado puede mermar solo
```

### La regla mas importante del modelo

**Si un producto no tiene regla, el lote se produce SIN fecha de
vencimiento.**

No con un plazo por omision, no con "hoy", no con "tres dias". Sin fecha.

Es preferible un hueco visible a una regla inventada. Un `NULL` se ve en
pantalla como "este producto no tiene regla" y alguien lo configura. Un plazo
inventado aparece despues en un informe como si alguien lo hubiera decidido, y
eso ya no se puede distinguir de una regla real.

Y hay un detalle fino: una fila con `dias_vigencia = NULL` significa "este
producto **no** caduca", que es distinto de `dias_vigencia = 0`, que significa
"caduca hoy". Por eso `NULL` y `0` son cosas distintas y la columna acepta los
dos.

## El lote: por que la mercaderia necesita origen

Un LOTE es mercaderia que se puede distinguir por fecha y por origen.

| Columna | Que es |
|---|---|
| `codigo` | `LOTE-<numero de tanda>-<fecha>`. Lleva el número de tanda y no un correlativo suelto, para poder reconstruir de donde salio una mercaderia leyendo el código, sin consultar la base. |
| `cantidad_inicial` | Cuanto salio del horno |
| `cantidad_disponible` | Cuanto queda. **Nunca se edita a mano.** |
| `fecha_produccion` | Cuando se hizo |
| `fecha_vencimiento` | Cuando deja de servirse. `NULL` si no hay regla. |
| `estado` | `disponible`, `agotado`, `vencido`, `mermado` |
| `ubicacion_id` | Donde esta ahora |

`vencido` y `mermado` estan separados a propósito: un lote vencido que todavia
no se mermo sigue siendo mercaderia, y hay que verlo. Solo cuando la merma se
registra pasa a `mermado`.

## El libro de movimientos

`movimientos_inventario` es la tabla mas importante del sistema, y la mas
simple.

| Columna | Regla |
|---|---|
| `tipo` | `ingreso`, `salida`, `ajuste`. Que PASÓ. |
| `sentido` | `entrada` o `salida`. Hacia donde fue. |
| `cantidad` | **Siempre positiva.** El signo lo pone el sentido. |

`tipo` y `sentido` son dos columnas y no una porque el ajuste va en los dos
sentidos: un ajuste por rotura es una salida, un ajuste por correccion de
conteo puede ser entrada o salida.

La cantidad positiva es una decisión, no una costumbre: un signo escondido en
la cantidad es la forma clasica de que un informe de merma cuadre al reves.

El saldo de un articulo es:

```
suma(entradas) - suma(salidas)
```

Los movimientos son **inmutables**. Hay un gatillo que prohibe modificarlos y
borrarlos. Si algo quedo mal escrito, la forma de corregirlo es registrar el
movimiento contrario, que queda a la vista. Borrar el error seria borrar la
evidencia.

## La merma, y por que esta separada del movimiento

El movimiento ya dice "salieron 12 unidades". La merma dice **por que**.

Sin el motivo, "merma: 300 unidades" no dice si se perdio pan quemado o si se
conto mal tres veces, y el informe no sirve para nada.

| `mermas` | `movimientos_inventario` |
|---|---|
| Explica la perdida | Baja el stock |
| `motivo_merma_id` es **obligatorio** | `motivo_merma_id` es opcional |
| `origen`: `manual` o `automatica` | `tipo`: `salida` |
| Para el informe | Para el saldo |

Una merma SIEMPRE deja las dos cosas escritas en la misma transacción. No hay
forma de registrar una perdida que no reste, ni una salida por perdida que no
quede en `mermas`.

`origen` separa la perdida que vino del reloj de la que vino de las manos, y
esa es la pregunta que una panaderia se hace al cerrar el dia.

## Las ventas de mostrador

`venta_items.lote_producto_id` no es un detalle. Sin el lote no se sabe que
pan se vendio ni cuando caduca lo que queda. Es lo que permite responder "de
los 30 panes que vendimos el martes, cuantos eran del lote que se quemo".

El precio se **copia** a la linea al momento de vender. Si manana suben los
precios, la venta de ayer tiene que seguir cuadrando con el precio de ayer.

El descuento no se guarda por linea sino en el `lotes_cierre`, que es donde de
verdad se aplica: cuando a una panaderia le quedan panes del dia anterior,
baja el precio de todo lo que esta en ese lote.

El orden de venta es por vencimiento: primero lo que vence antes. Y lo que
**no** tiene fecha de vencimiento va al final, con `NULLS LAST`. Sin ese
`NULLS LAST`, PostgreSQL pondria los nulos primero, y como `NULL` es lo mas
pequeno, el pan sin regla de vencimiento se venderia siempre primero, que es lo
contrario de lo razonable.

## Los pedidos de torta

| Por que esta separado del mostrador |
|---|
| Una venta de mostrador es pan que sale ahora. Un pedido de torta es un encargo que se toma hoy, se entrega en tres dias y a veces hay que producirlo el dia anterior porque la torta del cliente se terminaba temprano. |
| Mezclarlos obligaria a llenar de columnas en `NULL` las de cliente, fecha y anticipo en cada venta de pan. |
| Y el problema real es otro: un pedido que no esta agendado no existe, y una torta perdida es la perdida mas cara del negocio. |

**El estado NO se deduce de la fecha.** Un pedido que paso la fecha de entrega
sin despacharse NO se marca como perdido: sigue `confirmado` y aparece en la
agenda como atrasado. Marcarlo perdido automáticamente seria inventar un
hecho que todavia no se sabe.

| Estado | Que significa |
|---|---|
| `borrador` | Se acordo y no se confirmo. Sin dinero. |
| `confirmado` | Con anticipo. Es lo único que se puede confirmar: sin anticipo no hay protección contra una torta que se produce y no se cobra. |
| `en_produccion` | Esta en el horno. |
| `listo` | Para retirar o despachar. |
| `entregado` | Final. |
| `anulado` | Final. Sin anticipo: si queda dinero, hay que devolverlo antes. |

## Lo que NO esta en el modelo, y por que

### No hay precio de costo

`articulos` tiene `precio_venta` y nada mas. No hay `precio_costo`.

No es un olvido: el `precio_venta` de un insumo es el precio de la bolsa que se
vende al público, **no** lo que costo comprarla. Multiplicar uno por otro para
calcular el costo de producir un pan daria una cifra inventada, y un número
falso es peor que un número ausente.

Por eso `CalculadoraDeRendimiento` devuelve `"costo": null` con una
explicacion, en vez de un número. Es una decisión: la interfaz dice que no se
puede calcular, en lugar de mostrar algo que parece real.

**Para resolverlo hace falta que la panaderia defina como se lleva el costo de
un insumo.** Es una pregunta de negocio, no técnica.

### El diseno de la torta es texto

`pedidos_torta.diseno` es `TEXT`. Guardar una imagen exigiria decidir ahora el
almacenamiento, los permisos y el respaldo de un archivo binario, que son
decisiones de infraestructura y de costo.

### `tamano` y `sabor` son texto libre

Cada panaderia los llama distinto: "12 pulgadas", "mediana", "grande", "1 kilo".
Una tabla de tamanos obligaria a elegir uno para siempre.

### `hora_entrega` es texto, no una hora

Se guarda `"HH:MM"` porque el pedido se confirma en una conversacion y a veces
el cliente dice "mediodia".

## Las reglas de integridad que estan en la base

No son cortesias: son restricciones que impiden datos imposibles.

| Restriccion | Que impide |
|---|---|
| `lote_productos`: `cantidad_disponible <= cantidad_inicial` | Un lote que aparecio sin que nadie lo produjera. |
| `lote_productos`: `cantidad_disponible >= 0` | Vender lo que no hay. |
| `lote_productos`: `fecha_vencimiento >= fecha_produccion` | Caducar antes de hacerse. |
| `movimientos_inventario`: `cantidad > 0` | Movimientos de cero o negativos. |
| `movimientos_inventario`: `sentido IN ('entrada','salida')` | Un tercer sentido que nadie sabe interpretar. |
| `mermas`: `cantidad > 0` | Registrar una merma de nada. |
| `mermas`: `motivo_merma_id` NOT NULL | Una perdida sin explicacion. |
| `receta_ingredientes`: `cantidad > 0` | Un insumo de cantidad negativa. |
| `receta_ingredientes`: `merma_porcentaje >= 0 AND < 100` | Una merma mayor al 100 %, que no significa nada. |
| `ventas`: `lotes_cierre`: `descuento_porcentaje >= 0 AND < 100` | Un descuento de 150 %. |
| `pedidos_torta`: `saldo >= 0` | Un pedido con saldo a favor sin que nadie lo haya decidido. |
| `despachos`: `UNIQUE (tenant_id, pedido_torta_id)` | Despachar dos veces el mismo pedido. |
| Todas con `tenant_id`: `UNIQUE (tenant_id, ...)` en los códigos | Que dos panaderias usen el mismo SKU. |
| Todas con `tenant_id`: `restrictOnDelete` | Borrar en cascada datos de una panaderia. |