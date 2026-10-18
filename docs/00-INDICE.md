# Gestión de Panaderia y Pasteleria

Aplicación de Hefesto Studio para una panaderia pequena: producción por
tanda, merma con motivo, caducidad por fecha, venta de mostrador y pedidos de
torta con despacho.

**Estado: NO EJECUTADA.** Nada de esto se ha probado. No hay migraciones
aplicadas, ni pruebas corridas, ni imagenes construidas. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

## Documentos

| Documento | De que trata |
|---|---|
| [00-INDICE.md](00-INDICE.md) | Este indice, y como se lee el conjunto. |
| [01-ARQUITECTURA.md](01-ARQUITECTURA.md) | Las capas, por que el navegador no habla con la API, y el flujo de una peticion. |
| [02-MODELO-DE-DATOS.md](02-MODELO-DE-DATOS.md) | Las tablas, sus reglas y los motivos de cada decisión. |
| [03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md) | Como una panaderia no ve los datos de otra. Los cinco roles y que puede cada uno. |
| [04-INSTALACION.md](04-INSTALACION.md) | Como se levanta, paso a paso, y que hay que revisar antes de abrir al público. |
| [05-CONFIGURACION.md](05-CONFIGURACION.md) | Las reglas que decide la panaderia: vigencias, motivos, lotes de cierre. |
| [06-PRIMERA-PRODUCCION.md](06-PRIMERA-PRODUCCION.md) | Del catalogo al primer lote, y como se cierra el turno con el sobrante. |
| [07-SEGURIDAD.md](07-SEGURIDAD.md) | Identidad, sesión, por que una respuesta es 404 y no 403, y que puertos se publican. |
| [08-RESPALDOS.md](08-RESPALDOS.md) | Como se respalda, como se restaura, y las seis reglas que no se negocian. |
| [09-API.md](09-API.md) | Los endpoints, que códigos devuelven y que errores son del negocio. |
| [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md) | **La verdad:** que se escribio, que no se ejecuto, y que falta verificar. |

## Lo que esta aplicación resuelve de verdad

No es un restaurante con caja. Es una panaderia que produce, pierde
mercaderia, y tiene que saber cuanto perdio.

- **Producción por tanda.** Cada corrida dice una receta y rinde una
  cantidad conocida. El pan que salio del horno es un LOTE, con su fecha y su
  origen.
- **Merma con motivo.** El pan quemado, el que se cayo y el que vencio se
  registran con su motivo, obligatoriamente. Sin motivo no hay registro.
- **Sobrante del turno anterior.** Lo que queda de ayer o se merma con su
  motivo, o pasa a la bodega y aparece en el inventario de hoy. No hay una
  tercera salida, y esa es justamente la garantia de que la merma no se
  esconda.
- **Caducidad por fecha.** El pan caduca hoy y el sándwich mañana: la
  vigencia se CONFIGURA por producto. El sistema no inventa plazos.
- **Recetas con insumos que también se venden.** La harina del pan y la bolsa
  de harina del mostrador son el MISMO articulo, en el MISMO inventario.
- **Venta de mostrador.** Venta rapida con pago y descuento por lote de
  cierre.
- **Pedidos de torta.** El encargo con fecha, diseno y anticipo: la venta de
  mayor valor y la que se pierde si no se agenda.

## Lo que NO esta resuelto

Esta lista esta para que nadie asuma que algo existe cuando no existe. Cada
punto dice donde se explica en detalle.

| Falta | Por que |
|---|---|
| Precio de costo de los insumos | No hay de donde sacarlo. El `precio_venta` de un insumo es el precio de la bolsa que se vende, no lo que costo comprarla. Por eso `CalculadoraDeRendimiento` no calcula costo. Ver [02](02-MODELO-DE-DATOS.md). |
| Imagen del diseno de una torta | Habria que decidir almacenamiento, permisos y respaldo de archivos binarios. Hoy el diseno es texto. Ver [02](02-MODELO-DE-DATOS.md). |
| Valores de negocio por omision | Los dias de aviso de caducidad y los minutos maximos de venta tienen un valor por omision que **no** confirmo la panaderia. Ver [05](05-CONFIGURACION.md). |
| Verificación de todo | Nada se ejecuto. Ver [10](10-PRUEBAS-Y-ESTADO.md). |