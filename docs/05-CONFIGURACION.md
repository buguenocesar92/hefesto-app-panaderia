# 05. Configuración

Esta es la parte del sistema que la panaderia decide. Todo lo de aca son
**reglas de negocio**, y ninguna se puede inventar: si no se sabe, se deja en
blanco a la vista.

## La regla general

**Ante la duda, el hueco antes que el dato inventado.**

Un plazo por omision parece inofensivo y no lo es: aparece en un informe como
si alguien lo hubiera decidido, y despues ya no se puede distinguir de una
regla real. Un `NULL` se ve como "esto falta configurarlo", y alguien lo
configura.

## Las vigencias: cuando caduca cada producto

### Donde se configuran

`Configuracion > Vigencias`, o `POST /api/v1/configuracion/vigencias`.

| Campo | Que significa |
|---|---|
| `dias_vigencia` | Dias desde la producción hasta que caduca. **`NULL` = este producto NO caduca.** |
| `merma_automatica` | Si el trabajo programado puede registrar la merma solo cuando el lote vence. |
| `notas` | Texto libre. Por que esa regla. |

### Los tres casos, que son distintos

| Valor | Significado | Ejemplo |
|---|---|---|
| `5` | Caduca a los 5 dias de hacerse | Una torta de queso |
| `0` | Caduca **hoy** | Pan de marraqueta |
| `NULL` | **No caduca** | Harina, azucar, mantequilla |

`0` y `NULL` son cosas distintas y por eso la columna admite los dos. `0`
significa "caduca el mismo dia"; `NULL` significa "no hay regla de caducidad".

Por que existe `NULL`: hay productos que de verdad no caducan, y meterlos en
la tabla de vigencias con un número enorme seria mentir sobre la diferencia.

### El valor por omision

```
PANADERIA_VIGENCIA_POR_DEFECTO_DIAS=
```

Esta en blanco a propósito.

Si un producto **no tiene fila** en `vigencias` y no hay valor por omision, el
lote se produce **sin fecha de vencimiento** y no caduca solo.

Si alguien configura un valor por omision, los productos sin fila propia lo
heredan. Es una decisión legitima, pero tiene que ser consciente: significa
que la panaderia acepto que un producto al que no le puso regla tenga un
plazo.

Una fila con `dias_vigencia = NULL` **gana** sobre el valor por omision. Si el
administrador escribio que ese producto no caduca, esa es la decisión.

### La merma automática

| Si `merma_automatica` esta... | Cuando el lote vence |
|---|---|
| Activado | El trabajo programado registra la merma con el motivo `vencido` y descuenta el stock |
| Desactivado | El lote se marca `vencido` y **queda ahi**, visible, esperando a que una persona decida |

Ninguno de los dos es el valor "correcto" por omision. Con `false`, la
panaderia ve lo que se esta echando a perder antes de que sea un hecho
consumado.

Que este desactivado no es dejar de hacer nada: el lote sigue marcado
`vencido` y **no se puede vender**. Lo que se evita es que la decisión la tome
una maquina.

## Los motivos de merma

### Por que son configurables

Sin motivo, "merma: 300 unidades" no dice si se perdio pan quemado o si se
conto mal tres veces. El motivo es lo que hace que el informe signifique algo.

### Los que trae el ejemplo

| Código | Nombre | Por vencimiento |
|---|---|---|
| `vencido` | Vencido | si |
| `quemado` | Quemado en el horno | no |
| `caido` | Caido al piso | no |
| `error_conteo` | Error de conteo | no |

**`vencido` tiene un código fijo.** No es opcional ni se renombra: el trabajo
programado lo busca por ese código. Si la panaderia no lo crea, los lotes
vencidos se marcan pero **no se merman**, y el sistema avisa por consola.

### `merma_por_vencimiento`

Separa las dos cosas que la gente suele llamar "merma" y que en el informe
significan distinto: lo que se perdio por el tiempo y lo que se perdio por un
error.

### Dar de baja un motivo

Se marca `activo = false`, no se borra. Un motivo dado de baja no puede
explicar mermas nuevas: si el informe agrupa por motivo, "quemado" tiene que
significar lo mismo el lunes que el viernes.

Si el motivo ya se uso, borrarlo dejaria mermas sin explicacion.

## Los lotes de cierre

### Que son

El grupo de ventas que comparten descuento del dia.

Es como una panaderia liquida de verdad la mercaderia que se acerca a su
vencimiento: no se le pone descuento producto por producto, se abre un lote de
cierre con el descuento del dia y se vende todo lo que esta en el.

| Campo | Que significa |
|---|---|
| `nombre` | Como se le llama. "Cierre de la tarde". |
| `fecha` | El dia del cierre. |
| `descuento_porcentaje` | De 0 a menos de 100. |
| `estado` | `abierto` o `cerrado`. |

### Abrir y cerrar

Abrir uno no es privilege: es parte de la operación normal. El descuento lo
decide la panaderia cada manana según lo que le queda.

**Cerrarlo es lo que cierra la caja del dia**, y solo lo puede hacer el
encargado o el administrador. Cuando queda cerrado:

- Las ventas ya no pueden entrar a ese lote.
- Una venta cobrada ayer con el descuento de ayer, cerrada hoy con otro,
  haria el arqueo imposible de entender.

Al cerrar se informa cuantas ventas quedaron **abiertas** en el lote. Deben
anularse para que la caja cuadre.

### Por que el descuento se copia a cada linea

Al vender, el precio con descuento se **copia** a `venta_items.precio_unitario`.

Si manana sube el precio, la venta de ayer tiene que seguir cuadrando con el
precio de ayer. Por eso el descuento no se calcula al mostrar un reporte: se
congela en el momento de la venta.

## Los precios

`articulos.precio_venta` acepta `NULL`, y eso es una decisión.

El precio es una decisión comercial de la panaderia. El sistema no la
inventa: si no hay precio, el mostrador **no puede cobrar** ese articulo y lo
dice antes de sacar el pan del almacen, no despues.

### Lo que no hay: precio de costo

No existe `precio_costo` en el modelo.

El `precio_venta` de un insumo es el precio de la bolsa que se vende al
público, **no** lo que costo comprarla. Multiplicar uno por otro daria un
costo de producción inventado.

Por eso:

- `GET /api/v1/recetas/{id}/rendimiento` devuelve `"costo": null` con una
  explicacion.
- El reporte de merma devuelve `"valor_perdido": null` cuando el articulo no
  tiene precio, en vez de `0`.

Un `0` afirmaria que no se perdio nada. Un `null` dice que no se sabe.

**Para resolverlo hace falta que la panaderia defina como se lleva el costo de
un insumo.** Ver [02-MODELO-DE-DATOS.md](02-MODELO-DE-DATOS.md).

## Las unidades

| Campo | Que significa |
|---|---|
| `codigo` | La abreviatura. `UN`, `G`, `KG`. |
| `nombre` | El nombre completo. |
| `unidades_por_base` | Cuantas unidades base trae UNA de esta. Tiene que ser mayor que cero. |

Una bolsa de 25 kilos seria una unidad con factor 25000.

El factor esta en la unidad y no en el articulo porque dos articulos que se
venden en bolsas de distinto peso necesitan dos unidades distintas, aunque las
dos se llamen "bolsa".

## Las ubicaciones

| Tipo | Para que |
|---|---|
| `bodega` | Lo que esta guardado y no se ofrece |
| `mostrador` | Lo que se ofrece al público |
| `refrigeracion` | Lo que necesita frio |

La ubicacion responde a la pregunta "donde quedo esta bolsa de pan", que es la
pregunta del dia a dia cuando sobra pan. Sin ella, el sobrante se termina
mermando por no encontrarlo.

### Un traslado no genera movimiento

Mover un lote del mostrador a la bodega **no** escribe en
`movimientos_inventario`.

Las unidades no entraron ni salieron del local, así que contarlas seria sumar
dos veces la misma mercaderia. Por eso `ubicacion_id` no esta entre las
columnas protegidas del gatillo de la migración 000005.

Hay una prueba que lo comprueba:
`SobranteDelTurnoTest::test_un_traslado_no_engaña_al_libro_de_movimientos`.

## Las variables de entorno

En `api/.env.example` y `deploy/.env.example`.

| Variable | Por omision | Estado |
|---|---|---|
| `PANADERIA_VIGENCIA_POR_DEFECTO_DIAS` | sin valor (NULL) | Decidido: no se inventa un plazo |
| `PANADERIA_DIAS_AVISO_CADUCIDAD` | 1 dia | **Pendiente de confirmar.** Solo es una ventana de aviso de la interfaz, no una regla de negocio |
| `PANADERIA_MINUTOS_MAXIMOS_VENTA` | 10 minutos | **Pendiente de confirmar.** Es lo que evita que una venta quede abierta y la caja descuadrada |
| `PANADERIA_MERMA_POR_DEFECTO_PORCENTAJE` | 0 % | Decidido: 0 es un hecho (no se declaro merma), cualquier otro número seria una suposicion |
| `PANADERIA_CORRER_MIGRACIONES` | `false` | Decidido: las migraciones se aplican a mano |

Los dos marcados como **pendientes** son los unicos valores por omision de este
sistema que no salieron de una regla de negocio verificada. Son detalles de interfaz, no reglas de panaderia, pero **la panaderia tiene que
confirmarlos** antes de abrir.

Ver [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).