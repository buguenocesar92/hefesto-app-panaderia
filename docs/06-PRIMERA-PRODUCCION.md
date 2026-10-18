# 06. Primera producción

Este es el camino completo, desde cero hasta el cierre del turno. Esta escrito
como se haria la primera vez, con los comandos que hay que correr y lo que hay
que mirar.

**Ninguno de estos pasos se ejecuto.** Es la secuencia que *deberia* funcionar;
que funcione no esta verificado. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

## Antes: el catalogo tiene que existir

Sin unidades, articulos y ubicaciones no hay nada que producir. Ver
[05-CONFIGURACION.md](05-CONFIGURACION.md).

## Paso 1. Ver que rinde la receta y si alcanza

Esta es la pregunta del dia a dia del hornero, y conviene responderla **antes**
de abrir el horno.

```
GET /api/v1/recetas/{id}/rendimiento?tandas=3
```

La respuesta trae dos cuentas que van en sentidos distintos y que es facil
confundir:

| Cuenta | Que es |
|---|---|
| `rendimiento_total` | Lo que **SALE** del horno. 40 por tanda, 3 tandas = 120. |
| `insumos[].cantidad_total` | Lo que hay que **SACAR** del almacen. |

La cantidad que se saca incluye la merma estimada del insumo. Si la receta
pide 1000 gramos y la merma estimada es 5 %, hay que sacar 1050, no 1000. Si
se usara el mismo número para las dos cosas, al final de la semana faltaria
harina para el último pan y no se sabria por que.

Si `alcanza` es `false`, `faltantes` dice que falta que y en que cantidad.

## Paso 2. Agendar la corrida

```
POST /api/v1/tandas
{
  "receta_id": "...",
  "fecha": "2026-03-10",
  "cantidad_planificada": 120,
  "notas": "Tanda de la manana"
}
```

`cantidad_planificada` es lo que se **quiso** hacer. Lo que realmente salio
del horno se carga en el paso 3. Que sean dos columnas y no una es a
propósito: la diferencia entre plan y realidad es información de la
panaderia, y sobrescribir una con la otra seria perderla.

## Paso 3. Registrar que salio del horno

```
POST /api/v1/tandas/{id}/registrar
{
  "cantidad_producida": 118,
  "ubicacion_id": "...",
  "numero_tandas": 3
}
```

Esto pasa en **una sola transacción** y hace cuatro cosas, en este orden:

```
  1. Descontar los insumos de la tanda
       por cada insumo, una salida con la referencia del numero de tanda
       (los insumos a granel NO llevan lote: no caducan por el dia)

  2. Crear el lote con lo que salio del horno
       codigo: LOTE-TA-20260310-0001-20260310
       cantidad_inicial = 118
       fecha_vencimiento = calculada desde la vigencia del producto
         (si el producto NO tiene regla, queda en NULL)

  3. Registrar la entrada del producto horneado
       un movimiento de entrada, que es la unica via por la que ese
       saldo puede existir

  4. Cerrar la tanda con la realidad
       estado = terminada
       cantidad_producida = 118
```

Si falta un insumo, **no se toca nada**: se devuelve `422` con el código
`sin_insumos` y el detalle de que falta cuanto.

La prueba `RendimientoDeRecetaTest::test_una_tanda_sin_insumos_no_toca_nada`
comprueba justamente eso, porque descontar la mitad y fallar despues dejaria
el almacen con la receta a medias y el hornero sin pan.

## Paso 4. Que fecha le sale al lote

La respuesta dice:

```json
{
  "lote": {
    "codigo": "LOTE-TA-20260310-0001-20260310",
    "cantidad_inicial": 118,
    "fecha_vencimiento": "2026-03-11",
    "tiene_regla_vigencia": true
  }
}
```

Si `fecha_vencimiento` viene en `null` y `tiene_regla_vigencia` en `false`,
**el producto no tiene regla de vigencia configurada**. No es un error: es la
forma de que la panaderia se entere de que le falta configurar ese producto.

El lote existe igual, se puede vender igual, y no caduca solo. Pero en el
inventario aparece como "no caduca", y en la configuración como un producto
sin regla.

## Paso 5. Vender en el mostrador

El mostrador descuenta del lote **al abrir la venta**, no al cobrar. El pan ya
salio de la vitrina aunque el pago este pendiente; dejarlo para el cobro
permitiria vender dos veces lo mismo.

El lote se elige por vencimiento: primero el que vence antes. Y lo que **no**
tiene fecha de vencimiento va al final.

Si un articulo no tiene precio, la venta se rechaza antes de sacar nada del
almacen.

## Paso 6. Cerrar el turno: el sobrante

Esta es la parte que hace visible la merma, y la razon de que el sistema
exista.

```
POST /api/v1/tandas/{id}/sobrante
{
  "bodega_id": "..."
}
```

Sin este paso, la respuesta real en una panaderia es que las 6 marraquetas que
quedaron se quedan paradas en un cajon hasta que alguien las ve, y para
entonces ya pasaron dos dias y se pierden sin que nadie lo escriba.

Con este paso, el cierre hace **una de dos cosas, y siempre una**:

| Si el lote... | Que pasa |
|---|---|
| Ya vencio | Se registra la **merma** con el motivo `vencido`. El saldo baja a cero. |
| Todavia sirve | Pasa a la **bodega** y aparece en el inventario del dia siguiente. |

**No hay un tercer caso.** "Lo dejare para manana" sin escribir nada no es una
opcion, porque es exactamente como la merma se esconde.

La respuesta dice cuanto sobraba y que se hizo con cada lote:

```json
{
  "total_sobrante": 14,
  "traspasados": [{ "codigo": "LOTE-TA-20260310-0001-20260310", "cantidad": 14, "a": "Bodega" }],
  "mermados": []
}
```

### El detalle fino: un traslado no es un movimiento

El lote que pasa a la bodega cambia de ubicacion, pero **no** genera un
movimiento de inventario.

Las unidades no entraron ni salieron del local. Si se contara, el saldo del
articulo se doblaria y el inventario dejaria de cuadrar con el libro.

Lo que se escribe es el cambio de `ubicacion_id`, que por eso no esta entre las
columnas protegidas del gatillo.

## La cadena completa, de un vistazo

```
  Catálogo, recetas y vigencias configurados
              |
              v
  ¿Alcanza el stock?  -- no -->  422: falta que y cuanto. No se toca nada.
              | si
              v
  Tanda registrada (1 transaccion)
    insumos salen, lote nace, entrada del producto
              |
              v
  Ventas de mostrador
    cada venta descuenta del lote que vence antes
              |
              v
  Cierre del turno
    lo vencido --> merma con motivo
    lo que sirve --> bodega, visible mañana
              |
              v
  Merma por error (quemado, caido)
    motivo obligatorio, movimiento obligatorio
              |
              v
  Reporte: cuanto se perdió, en qué, y de quién fue
```

## Que mirar si algo no cuadra

| Sintoma | Donde mirar |
|---|---|
| "No hay stock suficiente" | `/inventario`: el saldo del insumo. Y `/recetas`: si la merma estimada esta agrandando la cantidad |
| El lote salio sin fecha de vencimiento | `/configuracion/vigencias`: ese producto no tiene regla |
| La merma no descuenta el stock | `/inventario/lotes/{codigo}`: los movimientos del lote |
| El saldo no coincide con la suma de los lotes | `/articulos/{id}/movimientos`: hay un movimiento mal escrito |
| Un lote vencido todavia se puede vender | El trabajo programado no esta corriendo. `schedule:list` |

## Sobre el gasto por lote de cierre

Cuando a la panaderia le quedan panes del dia anterior, lo normal es bajarles
el precio. Eso se hace con un **lote de cierre**: se abre con el descuento del
dia, y todas las ventas que entren a el lo llevan.

Al cerrar el dia, el encargado cierra el lote y compara el total con el dinero
en la caja. Si quedan ventas abiertas en el lote, el sistema avisa: una venta
abierta con pan ya descontado es una caja descuadrada que nadie puede explicar
despues.

Ver [05-CONFIGURACION.md](05-CONFIGURACION.md).